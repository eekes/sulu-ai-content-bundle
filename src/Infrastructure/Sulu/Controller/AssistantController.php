<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantRequest;
use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Assistant\FactMemory;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TemplateInspectorInterface;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Message\RunAssistant;
use Eekes\Sulu\AiContentBundle\Application\Model\ModelRegistry;
use Eekes\Sulu\AiContentBundle\Application\Usage\BudgetExceededException;
use Eekes\Sulu\AiContentBundle\Application\Usage\BudgetGuard;
use Eekes\Sulu\AiContentBundle\Application\Usage\CostCalculator;
use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;
use Eekes\Sulu\AiContentBundle\Domain\Entity\Conversation;
use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;
use Eekes\Sulu\AiContentBundle\Domain\Repository\AiRunRepository;
use Eekes\Sulu\AiContentBundle\Domain\Repository\ConversationRepository;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Admin\AiContentAdmin;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Admin\RunPresenter;
use Eekes\Sulu\AiContentBundle\Infrastructure\Symfony\EventListener\DeferredRunDispatcher;
use Psr\Clock\ClockInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The assistant panel's API: start a conversation, send a turn, and poll the run of that turn.
 *
 * A turn is a run in the usage log from the moment it is sent. The run itself is a Messenger
 * message, dispatched once the response is out: routed to an async transport it happens in a
 * worker, otherwise right after this request - either way the panel polls it until it is finished.
 */
final class AssistantController extends AbstractAdminController
{
    public const string CONVERSATION_RESOURCE_KEY = Conversation::RESOURCE_KEY;
    public const string TURN_RESOURCE_KEY = 'eekes_ai_content_turns';
    public const string PLAN_OPTIONS_RESOURCE_KEY = 'eekes_ai_content_plan_options';
    public const string HISTORY_RESOURCE_KEY = 'eekes_ai_content_conversation_history';

    public const string POST_CONVERSATION_ROUTE = 'eekes_ai_content.post_conversation';
    public const string GET_CONVERSATIONS_ROUTE = 'eekes_ai_content.get_conversations';
    public const string GET_CONVERSATION_ROUTE = 'eekes_ai_content.get_conversation';
    public const string GET_PLAN_OPTIONS_ROUTE = 'eekes_ai_content.get_plan_options';
    public const string PATCH_CONVERSATION_ROUTE = 'eekes_ai_content.patch_conversation';
    public const string POST_TURN_ROUTE = 'eekes_ai_content.post_turn';
    public const string GET_TURN_ROUTE = 'eekes_ai_content.get_turn';

    public function __construct(
        SecurityCheckerInterface $securityChecker,
        private readonly ConversationRepository $conversationRepository,
        private readonly AiRunRepository $runRepository,
        private readonly ModelRegistry $modelRegistry,
        private readonly CostCalculator $costCalculator,
        private readonly BudgetGuard $budgetGuard,
        private readonly DeferredRunDispatcher $runDispatcher,
        private readonly RunPresenter $runPresenter,
        private readonly FactMemory $factMemory,
        private readonly TemplateInspectorInterface $templateInspector,
        private readonly PageFinderInterface $pageFinder,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly AssistantScope $scope,
    ) {
        parent::__construct($securityChecker);
    }

    #[Route(path: '/ai-content/conversations', name: self::POST_CONVERSATION_ROUTE, methods: ['POST'])]
    public function postConversation(Request $request): JsonResponse
    {
        $this->denyUnlessGranted(AiContentAdmin::ASSISTANT_SECURITY_CONTEXT, PermissionTypes::VIEW);

        $payload = $request->toArray();
        $formKey = \is_string($payload['formKey'] ?? null) ? $payload['formKey'] : '';
        $locale = \is_string($payload['locale'] ?? null) ? $payload['locale'] : '';

        if ($formKey === '' || $locale === '') {
            throw new BadRequestHttpException('"formKey" and "locale" are required.');
        }

        $resourceId = $payload['resourceId'] ?? null;

        $conversation = new Conversation(
            $formKey,
            $locale,
            \is_string($payload['webspace'] ?? null) && $payload['webspace'] !== '' ? $payload['webspace'] : null,
            \is_string($resourceId) || \is_int($resourceId) ? (string) $resourceId : null,
            $this->currentUserId(),
        );

        $this->conversationRepository->save($conversation);

        return $this->json($this->presentConversation($conversation), 201);
    }

    /**
     * The history of the panel: the conversations of the current user on this page.
     */
    #[Route(path: '/ai-content/conversations', name: self::GET_CONVERSATIONS_ROUTE, methods: ['GET'])]
    public function getConversations(Request $request): JsonResponse
    {
        $this->denyUnlessGranted(AiContentAdmin::ASSISTANT_SECURITY_CONTEXT, PermissionTypes::VIEW);

        $userId = $this->currentUserId();
        $formKey = $request->query->getString('formKey');
        $resourceId = $request->query->getString('resourceId');
        $locale = $request->query->getString('locale');

        if ($userId === null || $formKey === '' || $resourceId === '' || $locale === '') {
            return $this->json(['conversations' => []]);
        }

        $conversations = array_map(static fn (Conversation $conversation) => [
            'id' => $conversation->id,
            'title' => $conversation->getTitle(),
            'turns' => \count(array_filter($conversation->getMessages(), static fn (array $message) => $message['role'] === 'user')),
            'createdAt' => $conversation->createdAt->format(\DATE_ATOM),
            'updatedAt' => $conversation->updatedAt->format(\DATE_ATOM),
        ], $this->conversationRepository->findForResource($userId, $formKey, $resourceId, $locale));

        return $this->json(['conversations' => $conversations]);
    }

    #[Route(path: '/ai-content/conversations/{id}', name: self::GET_CONVERSATION_ROUTE, methods: ['GET'])]
    public function getConversation(string $id): JsonResponse
    {
        $this->denyUnlessGranted(AiContentAdmin::ASSISTANT_SECURITY_CONTEXT, PermissionTypes::VIEW);

        return $this->json($this->presentConversation($this->ownConversation($id)));
    }

    /**
     * Links a conversation started on a new page, article or snippet to it once it is saved and
     * has an id. A conversation that is already linked keeps its resource.
     */
    #[Route(path: '/ai-content/conversations/{id}', name: self::PATCH_CONVERSATION_ROUTE, methods: ['PATCH'])]
    public function patchConversation(string $id, Request $request): JsonResponse
    {
        $this->denyUnlessGranted(AiContentAdmin::ASSISTANT_SECURITY_CONTEXT, PermissionTypes::VIEW);

        $conversation = $this->ownConversation($id);
        $payload = $request->toArray();
        $resourceId = $payload['resourceId'] ?? null;
        $planProgress = $payload['planProgress'] ?? null;

        if ((\is_string($resourceId) || \is_int($resourceId)) && (string) $resourceId !== '') {
            $conversation->attachTo((string) $resourceId);
        } elseif (!\is_array($planProgress)) {
            throw new BadRequestHttpException('"resourceId" or "planProgress" is required.');
        }

        if (\is_array($planProgress)) {
            $runId = $planProgress['runId'] ?? null;

            if (!\is_string($runId) || $runId === '' || !\is_array($planProgress['items'] ?? null)) {
                throw new BadRequestHttpException('"planProgress" needs a "runId" and "items".');
            }

            $conversation->recordPlanProgress($runId, self::planItems($planProgress['items']));
        }

        $this->conversationRepository->save($conversation);

        return $this->json($this->presentConversation($conversation));
    }

    /**
     * The progress of a plan as the planner sends it, reduced to strings: the planned item and its
     * state (with the id of what was created - and the URL of a new page the other items go
     * below - an error or the question that stopped it).
     *
     * @param array<mixed> $items
     *
     * @return list<array{item: array<string, mixed>, state: array<string, string>}>
     */
    private static function planItems(array $items): array
    {
        $result = [];

        foreach (\array_slice(array_values($items), 0, 50) as $entry) {
            if (!\is_array($entry) || !\is_array($entry['item'] ?? null) || !\is_array($entry['state'] ?? null)) {
                continue;
            }

            $item = [];

            foreach (['title', 'template', 'templateTitle', 'parent', 'parentTitle', 'brief', 'kind', 'key'] as $key) {
                $value = $entry['item'][$key] ?? null;
                $item[$key] = \is_string($value) ? $value : null;
            }

            $state = [];

            foreach (['state', 'id', 'url', 'error', 'message'] as $key) {
                if (\is_string($entry['state'][$key] ?? null)) {
                    $state[$key] = $entry['state'][$key];
                }
            }

            if (\is_string($item['title']) && isset($state['state'])) {
                $result[] = ['item' => $item, 'state' => $state];
            }
        }

        return $result;
    }

    #[Route(path: '/ai-content/conversations/{conversationId}/turns', name: self::POST_TURN_ROUTE, methods: ['POST'])]
    public function postTurn(string $conversationId, Request $request): JsonResponse
    {
        $this->denyUnlessGranted(AiContentAdmin::ASSISTANT_SECURITY_CONTEXT, PermissionTypes::VIEW);

        $conversation = $this->ownConversation($conversationId);
        $payload = $request->toArray();
        $assistantRequest = AssistantRequest::fromArray($payload);
        $resourceId = $payload['resourceId'] ?? null;

        if (\is_string($resourceId) || \is_int($resourceId)) {
            $conversation->attachTo((string) $resourceId);
        }

        // a plan has no form and no template yet
        if ($assistantRequest->templateKey === '' && $assistantRequest->action !== AiAction::PlanPages) {
            throw new BadRequestHttpException('"template" is required.');
        }

        // one turn at a time: a second one would be billed while the first is still being written
        $busyRun = $this->busyRun($conversation);

        if ($busyRun !== null) {
            return $this->json([
                'code' => 'turn_running',
                'message' => $this->translator->trans('eekes_ai_content.turn_running', [], 'admin', $this->userLocale()),
                'turn' => $busyRun->id,
            ], 409);
        }

        try {
            $this->budgetGuard->check($conversation->webspaceKey);
        } catch (BudgetExceededException $exception) {
            return $this->json(['code' => 'budget_exceeded', 'message' => $exception->getMessage()], 429);
        }

        $model = $this->modelRegistry->get($assistantRequest->modelKey);
        $run = new AiRun($assistantRequest->action, $model->key, $model->model, $this->costCalculator->getCurrency(), $conversation);
        $run->prompt = $assistantRequest->message;
        $this->runRepository->save($run);

        $conversation->addUserMessage($assistantRequest->message, $run->id);
        $this->conversationRepository->save($conversation);
        $this->factMemory->remember($conversation, $assistantRequest->answers);

        // run after the response has been sent, so the panel can poll the progress
        $this->runDispatcher->defer(new RunAssistant($run->id, $assistantRequest->toArray()));

        return $this->json($this->runPresenter->toTurn($run, $this->userLocale()), 202);
    }

    /**
     * What the plan editor of the planner offers: the templates (with the property that holds the
     * URL, so the panel can make it), the page templates for the page a plan of articles can
     * create, and the pages of the webspace to put new ones below.
     */
    #[Route(path: '/ai-content/plan-options', name: self::GET_PLAN_OPTIONS_ROUTE, methods: ['GET'])]
    public function getPlanOptions(Request $request): JsonResponse
    {
        $this->denyUnlessGranted(AiContentAdmin::ASSISTANT_SECURITY_CONTEXT, PermissionTypes::VIEW);

        $formKey = $request->query->getString('formKey');
        $locale = $request->query->getString('locale');
        $webspace = $request->query->getString('webspace');
        $templateKeys = array_values(array_filter(explode(',', $request->query->getString('templateKeys'))));

        if ($formKey === '' || $locale === '') {
            throw new BadRequestHttpException('"formKey" and "locale" are required.');
        }

        $webspaceKey = $webspace === '' ? null : $webspace;
        // the pages to put new ones below: those this editor may view
        $this->scope->enter($locale, $webspaceKey, $this->currentUserId());
        $templates = self::templateOptions($this->templateInspector->getTemplates($formKey, $locale, $webspaceKey), $templateKeys);
        // a plan that is not about pages can create the page its items go below
        $pageTemplates = $formKey === 'page' ? [] : self::templateOptions($this->templateInspector->getTemplates('page', $locale, $webspaceKey), []);

        $pages = $webspace === '' ? [] : array_map(
            static fn (array $page) => ['uuid' => $page['uuid'], 'title' => $page['title'], 'url' => $page['url'], 'depth' => $page['depth']],
            $this->pageFinder->tree($locale, $webspace, 500),
        );

        return $this->json(['templates' => $templates, 'pageTemplates' => $pageTemplates, 'pages' => $pages]);
    }

    /**
     * The templates for the plan editor, each with the property that holds the URL and the fields
     * the URL is made from, so the panel can make it.
     *
     * @param list<TypeDescription> $templates
     * @param list<string> $templateKeys only these, unless empty
     *
     * @return list<array{key: string, title: string, route: null|array{name: string, type: string, parts: list<string>}}>
     */
    private static function templateOptions(array $templates, array $templateKeys): array
    {
        $options = [];

        foreach ($templates as $template) {
            if ($templateKeys !== [] && !\in_array($template->key, $templateKeys, true)) {
                continue;
            }

            $route = null;
            $parts = [];

            foreach ($template->fields as $field) {
                if ($route === null && ($field->type === 'page_tree_route' || ($field->type === 'route' && \in_array('sulu.rlp', $field->tags, true)))) {
                    $route = ['name' => $field->name, 'type' => $field->type];
                }

                if (\in_array('sulu.rlp.part', $field->tags, true)) {
                    $parts[] = $field->name;
                }
            }

            // the fields the URL is made from, usually the title
            $options[] = ['key' => $template->key, 'title' => $template->title, 'route' => $route === null ? null : [...$route, 'parts' => $parts === [] ? ['title'] : $parts]];
        }

        return $options;
    }

    #[Route(path: '/ai-content/turns/{id}', name: self::GET_TURN_ROUTE, methods: ['GET'])]
    public function getTurn(string $id): JsonResponse
    {
        $this->denyUnlessGranted(AiContentAdmin::ASSISTANT_SECURITY_CONTEXT, PermissionTypes::VIEW);

        $run = $this->runRepository->findById($id);

        if ($run === null || $run->conversation === null || $run->userId !== $this->currentUserId()) {
            throw new NotFoundHttpException(\sprintf('The run "%s" does not exist.', $id));
        }

        return $this->json($this->runPresenter->toTurn($run, $this->userLocale()));
    }

    private function ownConversation(string $id): Conversation
    {
        $conversation = $this->conversationRepository->findById($id);

        // a conversation is private to whoever started it: it can hold unsaved drafts
        if ($conversation === null || $conversation->userId !== $this->currentUserId()) {
            throw new NotFoundHttpException(\sprintf('The conversation "%s" does not exist.', $id));
        }

        return $conversation;
    }

    /**
     * The run of the editor's latest message when it is still being worked on - in a worker, or
     * after the request that started it - so the panel opened again can wait for its reply.
     */
    private function busyRun(Conversation $conversation): ?AiRun
    {
        $runId = null;

        foreach ($conversation->getMessages() as $message) {
            if ($message['role'] === 'user') {
                $runId = $message['runId'] ?? null;
            }
        }

        $run = $runId === null ? null : $this->runRepository->findById($runId);

        return $run !== null && $run->isBusy($this->clock->now()) ? $run : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentConversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'formKey' => $conversation->formKey,
            'locale' => $conversation->locale,
            'webspace' => $conversation->webspaceKey,
            'resourceId' => $conversation->resourceId,
            'title' => $conversation->getTitle(),
            'messages' => $this->runPresenter->conversationMessages($conversation),
            'planProgress' => (object) $conversation->getPlanProgress(),
            'runningTurn' => $this->busyRun($conversation)?->id,
        ];
    }
}
