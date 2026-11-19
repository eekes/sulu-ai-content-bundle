<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogFile;
use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TemplateInspectorInterface;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\WebspaceAccessInterface;
use Eekes\Sulu\AiContentBundle\Application\Model\ModelRegistry;
use Eekes\Sulu\AiContentBundle\Application\Prompt\SystemPromptBuilder;
use Eekes\Sulu\AiContentBundle\Application\Prompt\TurnPromptBuilder;
use Eekes\Sulu\AiContentBundle\Application\Schema\ContentSanitizer;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\SnippetFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\PlaceholderFinder;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;
use Eekes\Sulu\AiContentBundle\Application\Usage\UsageRecorder;
use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;
use Eekes\Sulu\AiContentBundle\Domain\Entity\Conversation;
use Eekes\Sulu\AiContentBundle\Domain\Entity\WebspaceContext;
use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;
use Eekes\Sulu\AiContentBundle\Domain\Repository\ConversationRepository;
use Eekes\Sulu\AiContentBundle\Domain\Repository\WebspaceContextRepository;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Runs one turn of a conversation: builds the prompt, lets the model work (with its tools),
 * checks the content it proposes against the template and records everything in the run.
 *
 * Content that does not fit the template is sent back once with what was wrong; whatever is
 * still wrong after that is dropped and listed as a warning. A run never throws: a failure is
 * stored on the run, where the panel and the usage log show it.
 */
class AssistantRunner
{
    private const int REPAIR_ATTEMPTS = 1;

    public function __construct(
        private readonly AgentFactory $agentFactory,
        private readonly ModelRegistry $modelRegistry,
        private readonly TemplateInspectorInterface $templateInspector,
        private readonly CatalogFile $catalogFile,
        private readonly SystemPromptBuilder $systemPromptBuilder,
        private readonly TurnPromptBuilder $turnPromptBuilder,
        private readonly ContentSanitizer $contentSanitizer,
        private readonly ReplyParser $replyParser,
        private readonly AssistantScope $scope,
        private readonly WebspaceContextRepository $webspaceContextRepository,
        private readonly ConversationRepository $conversationRepository,
        private readonly PageFinderInterface $pageFinder,
        private readonly UsageRecorder $usageRecorder,
        private readonly WebspaceAccessInterface $webspaceAccess,
        private readonly PlaceholderFinder $placeholderFinder = new PlaceholderFinder(),
        private readonly int $siteStructureLimit = 150,
        private readonly int $maxPlanItems = 12,
    ) {
    }

    public function run(AiRun $run, Conversation $conversation, AssistantRequest $request): AiRun
    {
        $this->usageRecorder->start($run, 'reading_page');

        try {
            $reply = $this->execute($run, $conversation, $request);
        } catch (\Throwable $exception) {
            $this->usageRecorder->fail($run, $exception);

            return $run;
        }

        $conversation->addAssistantMessage($reply->message, $reply->toArray(), $run->id);
        $this->conversationRepository->save($conversation);
        $this->usageRecorder->succeed($run, $reply->toArray());

        return $run;
    }

    private function execute(AiRun $run, Conversation $conversation, AssistantRequest $request): AssistantReply
    {
        $locale = $conversation->locale;
        $schemaContext = new SchemaContext($locale, $conversation->webspaceKey);
        $this->scope->enter($locale, $conversation->webspaceKey, $conversation->userId);

        if ($request->action === AiAction::PlanPages) {
            return $this->plan($run, $conversation, $request);
        }

        $templates = $this->templateInspector->getTemplates($conversation->formKey, $locale, $conversation->webspaceKey);
        $template = $this->templateInspector->getTemplate($conversation->formKey, $request->templateKey, $locale, $conversation->webspaceKey)
            ?? throw new \RuntimeException(\sprintf('The template "%s" of "%s" does not exist.', $request->templateKey, $conversation->formKey));

        $catalog = $this->catalogFile->load();
        [$context, $examplePages, $siteStructure] = $this->website($conversation);

        // the templates of snippets, for the snippet selections of the page; a project without
        // snippets has no "snippet" form
        $snippetTemplates = $conversation->formKey === 'snippet' ? $templates : $this->templateInspector->getTemplates('snippet', $locale);

        $system = $this->systemPromptBuilder->build($conversation->formKey, $template, $templates, $catalog, $context, $schemaContext, $examplePages, $snippetTemplates, $siteStructure, $this->siteStructureLimit);
        $turn = $this->turnPromptBuilder->build($request->action, $request->message, $conversation->formKey, $template, $request->content, $catalog, $request->focusBlock, $request->focusProperty, $request->sourceContent, $request->sourceLocale, $locale);

        $messages = new MessageBag(Message::forSystem($system), ...$this->history($conversation, $run->id), ...[Message::ofUser($turn)]);
        $model = $this->modelRegistry->get($request->modelKey);
        $agent = $this->agentFactory->create($model);

        $this->usageRecorder->progress($run, 'thinking');
        $text = $this->call($run, $agent, $messages, $model->options);
        $reply = $this->replyParser->parse($text);
        $transcript = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $turn], ['role' => 'assistant', 'content' => $text]];

        if ($reply->type === AssistantReply::TYPE_CONTENT && $reply->template !== null && $reply->template !== $template->key) {
            $switched = $this->templateInspector->getTemplate($conversation->formKey, $reply->template, $locale, $conversation->webspaceKey);
            $template = $switched ?? $template;
        }

        for ($attempt = 0; $reply->type === AssistantReply::TYPE_CONTENT; ++$attempt) {
            [$snippets, $snippetErrors, $snippetManual] = $this->checkSnippets($reply, $snippetTemplates, $catalog, $schemaContext);
            $newSnippets = array_column($snippets, 'template', 'key');
            // what the form holds now: required fields that are already filled need not be sent again
            $base = $request->action === AiAction::Translate && $request->sourceContent !== null ? $request->sourceContent : $request->content;
            $sanitized = $this->contentSanitizer->sanitize($conversation->formKey, $template, $reply->properties, $catalog, $schemaContext->withNewSnippets($newSnippets), $base);
            $errors = [...$snippetErrors, ...$sanitized->errors];
            $placeholders = $this->placeholderFinder->find([$sanitized->data, $reply->seo, $reply->excerpt, array_column($snippets, 'properties')]);

            if (($errors === [] && $placeholders === []) || $attempt >= self::REPAIR_ATTEMPTS) {
                $warnings = [...$errors, ...$sanitized->manual, ...$snippetManual];

                if ($placeholders !== []) {
                    $warnings[] = \sprintf('Still contains placeholders to fill in: %s.', implode(', ', $placeholders));
                }

                $reply = $reply->withContent($sanitized->data, $reply->seo, $reply->excerpt, $warnings, self::referencedSnippets($snippets, $sanitized->data));

                break;
            }

            $this->usageRecorder->progress($run, 'checking_content');
            $problems = $errors;

            if ($placeholders !== []) {
                $problems[] = \sprintf('The content contains placeholders (%s). Never write placeholders: reply with "questions" instead and ask the editor for these facts - with "remember": true for facts about the organisation - or write the text without them.', implode(', ', $placeholders));
            }

            $repair = "Your reply cannot be used as it is. Fix these problems and send the complete reply again:\n- ".implode("\n- ", $problems);
            $messages = $messages->with(Message::ofAssistant($text))->with(Message::ofUser($repair));
            $text = $this->call($run, $agent, $messages, $model->options);
            $reply = $this->replyParser->parse($text);
            $transcript[] = ['role' => 'user', 'content' => $repair];
            $transcript[] = ['role' => 'assistant', 'content' => $text];
        }

        $this->usageRecorder->transcript($run, $transcript);

        return $reply->withOutline(self::nameOutline($reply->outline, $template, $templates));
    }

    /**
     * What the assistant knows about the website: its AI context, the example pages and the
     * page tree.
     *
     * @return array{0: null|WebspaceContext, 1: list<array{uuid: string, title: string}>, 2: list<array{uuid: string, title: string, template: null|string, url: null|string, depth: int, holds: array<string, int>}>}
     */
    private function website(Conversation $conversation): array
    {
        $locale = $conversation->locale;
        // the AI context of a webspace the editor does not work in stays out of the prompt
        $context = $conversation->webspaceKey === null || !$this->webspaceAccess->canUseWebspace($conversation->userId, $conversation->webspaceKey, $locale)
            ? null
            : $this->webspaceContextRepository->findOneByWebspace($conversation->webspaceKey, $locale);
        $examplePages = [];

        foreach ($context === null ? [] : $context->examplePages as $uuid) {
            $page = $this->pageFinder->get($uuid, $locale);

            if ($page !== null) {
                $examplePages[] = ['uuid' => $page['uuid'], 'title' => $page['title']];
            }
        }

        $siteStructure = $conversation->webspaceKey === null || $this->siteStructureLimit === 0
            ? []
            : $this->pageFinder->tree($locale, $conversation->webspaceKey, $this->siteStructureLimit);

        return [$context, $examplePages, $siteStructure];
    }

    /**
     * A plan of several new pages or articles. Every item is checked - its template exists, the
     * page it goes below exists (and has a URL, for what sits below a page by its URL) - and what
     * does not fit goes back to the model once.
     */
    private function plan(AiRun $run, Conversation $conversation, AssistantRequest $request): AssistantReply
    {
        $locale = $conversation->locale;
        $templates = $this->templateInspector->getTemplates($conversation->formKey, $locale, $conversation->webspaceKey);

        if ($request->templateKeys !== []) {
            $templates = array_values(array_filter($templates, static fn (TypeDescription $template) => \in_array($template->key, $request->templateKeys, true)));
        }

        if ($templates === []) {
            throw new \RuntimeException(\sprintf('"%s" has no templates to plan with.', $conversation->formKey));
        }

        // a plan of articles may hold the page they go below, when it does not exist yet
        $pageTemplates = $conversation->formKey === 'page' ? [] : $this->templateInspector->getTemplates('page', $locale, $conversation->webspaceKey);
        [$context, $examplePages, $siteStructure] = $this->website($conversation);
        $system = $this->systemPromptBuilder->buildPlanner($conversation->formKey, $templates, $this->catalogFile->load(), $context, $locale, $examplePages, $siteStructure, $this->siteStructureLimit, $pageTemplates);
        $planChecker = new PlanChecker($this->pageFinder, $this->maxPlanItems);
        $selected = $request->selectedPage === null ? null : $this->pageFinder->get($request->selectedPage, $locale);
        $turn = $this->turnPromptBuilder->buildPlan($request->message, $selected === null ? null : ['uuid' => $selected['uuid'], 'title' => $selected['title']]);

        $messages = new MessageBag(Message::forSystem($system), ...$this->history($conversation, $run->id), ...[Message::ofUser($turn)]);
        $model = $this->modelRegistry->get($request->modelKey);
        $agent = $this->agentFactory->create($model);

        $this->usageRecorder->progress($run, 'thinking');
        $text = $this->call($run, $agent, $messages, $model->options);
        $reply = $this->replyParser->parse($text);
        $transcript = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $turn], ['role' => 'assistant', 'content' => $text]];

        for ($attempt = 0; $reply->type === AssistantReply::TYPE_PLAN; ++$attempt) {
            [$plan, $errors] = $planChecker->check($reply->plan, $conversation->formKey, $locale, $templates, $pageTemplates, $siteStructure);

            if ($errors === [] || $attempt >= self::REPAIR_ATTEMPTS) {
                $reply = $reply->withPlan($plan, $errors);

                break;
            }

            $this->usageRecorder->progress($run, 'checking_plan');
            $repair = "Your plan cannot be used as it is. Fix these problems and send the complete plan again:\n- ".implode("\n- ", $errors);
            $messages = $messages->with(Message::ofAssistant($text))->with(Message::ofUser($repair));
            $text = $this->call($run, $agent, $messages, $model->options);
            $reply = $this->replyParser->parse($text);
            $transcript[] = ['role' => 'user', 'content' => $repair];
            $transcript[] = ['role' => 'assistant', 'content' => $text];
        }

        $this->usageRecorder->transcript($run, $transcript);

        return $reply;
    }

    /**
     * Puts the names editors know next to the keys of an outline: "FAQ from snippets" rather than
     * "faq_snippets". A key the template does not know keeps the key as its name.
     *
     * @param null|array{template: null|string, templateTitle: null|string, blocks: list<array{type: string, title: string, purpose: string, snippets: list<array{title: string, uuid: null|string}>}>} $outline
     * @param list<TypeDescription> $templates
     *
     * @return null|array{template: null|string, templateTitle: null|string, blocks: list<array{type: string, title: string, purpose: string, snippets: list<array{title: string, uuid: null|string}>}>}
     */
    private static function nameOutline(?array $outline, TypeDescription $template, array $templates): ?array
    {
        if ($outline === null) {
            return null;
        }

        foreach ($templates as $candidate) {
            if ($candidate->key === $outline['template']) {
                $template = $candidate;
                $outline['templateTitle'] = $candidate->title;
            }
        }

        $titles = [];
        self::collectTypeTitles($template->fields, $titles);

        foreach ($outline['blocks'] as $index => $block) {
            $outline['blocks'][$index]['title'] = $titles[$block['type']] ?? $block['type'];
        }

        return $outline;
    }

    /**
     * @param list<FieldDescription> $fields
     * @param array<string, string> $titles
     */
    private static function collectTypeTitles(array $fields, array &$titles): void
    {
        foreach ($fields as $field) {
            foreach ($field->types as $type) {
                $titles[$type->key] ??= $type->title;
                self::collectTypeTitles($type->fields, $titles);
            }
        }
    }

    /**
     * Checks every new snippet against its own snippet template, like the content of the page.
     *
     * @param list<TypeDescription> $snippetTemplates
     *
     * @return array{0: list<array{key: string, template: string, properties: array<string, mixed>}>, 1: list<string>, 2: list<string>}
     */
    private function checkSnippets(AssistantReply $reply, array $snippetTemplates, Catalog $catalog, SchemaContext $context): array
    {
        $byKey = [];

        foreach ($snippetTemplates as $snippetTemplate) {
            $byKey[$snippetTemplate->key] = $snippetTemplate;
        }

        $snippets = [];
        $errors = [];
        $manual = [];

        foreach ($reply->snippets as $snippet) {
            $snippetTemplate = $byKey[$snippet['template']] ?? null;

            if ($snippetTemplate === null) {
                $errors[] = \sprintf('snippets.%s: there is no snippet template "%s". Allowed: %s.', $snippet['key'], $snippet['template'], implode(', ', array_keys($byKey)));

                continue;
            }

            $sanitized = $this->contentSanitizer->sanitize('snippet', $snippetTemplate, $snippet['properties'], $catalog, $context);

            foreach ($sanitized->errors as $error) {
                $errors[] = \sprintf('snippets.%s.%s', $snippet['key'], $error);
            }

            foreach ($sanitized->manual as $notice) {
                $manual[] = \sprintf('snippets.%s.%s', $snippet['key'], $notice);
            }

            $snippets[] = ['key' => $snippet['key'], 'template' => $snippet['template'], 'properties' => $sanitized->data];
        }

        return [$snippets, $errors, $manual];
    }

    /**
     * Only the new snippets the content refers to: a snippet nothing selects would be created for
     * nothing.
     *
     * @param list<array{key: string, template: string, properties: array<string, mixed>}> $snippets
     * @param array<string, mixed> $data
     *
     * @return list<array{key: string, template: string, properties: array<string, mixed>}>
     */
    private static function referencedSnippets(array $snippets, array $data): array
    {
        $references = [];

        array_walk_recursive($data, static function (mixed $value) use (&$references): void {
            if (\is_string($value) && str_starts_with($value, SnippetFieldSchema::NEW_PREFIX)) {
                $references[substr($value, \strlen(SnippetFieldSchema::NEW_PREFIX))] = true;
            }
        });

        return array_values(array_filter($snippets, static fn (array $snippet) => isset($references[$snippet['key']])));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function call(AiRun $run, \Symfony\AI\Agent\AgentInterface $agent, MessageBag $messages, array $options): string
    {
        $execution = $agent->call($messages, $options);
        $execution->onProgress(function (Progress $progress) use ($run): void {
            $payload = $progress->getPayload();

            if ($progress->getStage() === Progress::STAGE_TOOL_CALL && $payload instanceof ToolCall) {
                $this->usageRecorder->toolCall($run, $payload->getName(), $payload->getArguments());
            }
        });

        $result = $execution->getResult();
        $this->usageRecorder->addUsage($run, $result);

        $content = $result->getContent();

        return \is_string($content) ? $content : '';
    }

    /**
     * Earlier turns of the conversation: the editor's words and the assistant's replies, without
     * the form data they came with.
     *
     * @return list<MessageInterface>
     */
    private function history(Conversation $conversation, string $currentRunId): array
    {
        $messages = [];

        foreach ($conversation->getMessages() as $message) {
            if (($message['runId'] ?? null) === $currentRunId) {
                continue;
            }

            if ($message['role'] === 'user') {
                $messages[] = Message::ofUser($message['content'] === '' ? '(no request)' : $message['content']);
            } else {
                $reply = $message['reply'] ?? ['message' => $message['content']];

                // what was proposed is in the form now, and the current turn sends the form
                if (isset($reply['properties']) && (array) $reply['properties'] !== []) {
                    $reply['properties'] = '(applied to the form)';
                }

                $messages[] = Message::ofAssistant(json_encode($reply, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
            }
        }

        return $messages;
    }
}
