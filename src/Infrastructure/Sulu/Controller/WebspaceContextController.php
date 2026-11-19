<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\AiContentBundle\Domain\Entity\WebspaceContext;
use Eekes\Sulu\AiContentBundle\Domain\Repository\WebspaceContextRepository;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Admin\AiContentAdmin;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "AI context" tab of a webspace. The webspace key is the id of the resource, so the admin's
 * ResourceStore loads and saves it like any other form.
 */
final class WebspaceContextController extends AbstractAdminController
{
    public const string ROUTE = 'eekes_ai_content.webspace_context';

    public function __construct(
        SecurityCheckerInterface $securityChecker,
        private readonly WebspaceContextRepository $contextRepository,
        private readonly WebspaceManagerInterface $webspaceManager,
    ) {
        parent::__construct($securityChecker);
    }

    #[Route(path: '/ai-content/contexts/{id}', name: self::ROUTE, methods: ['GET', 'PUT'])]
    public function context(string $id, Request $request): JsonResponse
    {
        if ($this->webspaceManager->findWebspaceByKey($id) === null) {
            throw new NotFoundHttpException(\sprintf('The webspace "%s" does not exist.', $id));
        }

        $locale = self::locale($request);
        $writing = $request->isMethod('PUT');
        $this->denyUnlessGranted(AiContentAdmin::getContextSecurityContext($id), $writing ? PermissionTypes::EDIT : PermissionTypes::VIEW);

        $context = $this->contextRepository->findOneByWebspace($id, $locale) ?? new WebspaceContext($id, $locale);

        if ($writing) {
            $payload = $request->toArray();

            foreach (WebspaceContext::TEXT_FIELDS as $field) {
                $value = $payload[$field] ?? null;
                $context->setText($field, \is_string($value) ? $value : null);
            }

            $context->setFacts(self::facts($payload['facts'] ?? null));
            $pages = $payload['examplePages'] ?? [];
            $context->examplePages = \is_array($pages) ? array_values(array_filter($pages, 'is_string')) : [];
            $this->contextRepository->save($context);
        }

        $data = [
            'id' => $id,
            'examplePages' => $context->examplePages,
            // the shape of a Sulu block field, so the tab edits the facts as a list
            'facts' => array_map(static fn (array $fact) => ['type' => 'fact'] + $fact, $context->getFacts()),
        ];

        foreach (WebspaceContext::TEXT_FIELDS as $field) {
            $data[$field] = $context->getText($field);
        }

        return $this->json($data);
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private static function facts(mixed $value): array
    {
        $facts = [];

        foreach (\is_array($value) ? $value : [] as $item) {
            if (\is_array($item) && \is_string($item['question'] ?? null) && \is_string($item['answer'] ?? null)) {
                $facts[] = ['question' => $item['question'], 'answer' => $item['answer']];
            }
        }

        return $facts;
    }
}
