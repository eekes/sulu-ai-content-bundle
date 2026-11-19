<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;
use Eekes\Sulu\AiContentBundle\Domain\Repository\AiRunRepository;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Admin\AiContentAdmin;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Admin\RunPresenter;
use Sulu\Component\Rest\ListBuilder\Doctrine\DoctrineListBuilderFactoryInterface;
use Sulu\Component\Rest\ListBuilder\Metadata\FieldDescriptorFactoryInterface;
use Sulu\Component\Rest\ListBuilder\PaginatedRepresentation;
use Sulu\Component\Rest\RestHelperInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The usage log: every run, newest first, filterable and exportable like any Sulu list.
 */
final class UsageController extends AbstractAdminController
{
    public const string LIST_ROUTE = 'eekes_ai_content.get_runs';
    public const string DETAIL_ROUTE = 'eekes_ai_content.get_run';

    public function __construct(
        SecurityCheckerInterface $securityChecker,
        private readonly AiRunRepository $runRepository,
        private readonly RunPresenter $runPresenter,
        private readonly RestHelperInterface $restHelper,
        private readonly FieldDescriptorFactoryInterface $fieldDescriptorFactory,
        private readonly DoctrineListBuilderFactoryInterface $listBuilderFactory,
    ) {
        parent::__construct($securityChecker);
    }

    #[Route(path: '/ai-content/runs', name: self::LIST_ROUTE, methods: ['GET'])]
    public function getList(Request $request): JsonResponse
    {
        $this->denyUnlessGranted(AiContentAdmin::USAGE_SECURITY_CONTEXT, PermissionTypes::VIEW);

        $listBuilder = $this->listBuilderFactory->create(AiRun::class);
        $fieldDescriptors = $this->fieldDescriptorFactory->getFieldDescriptors(AiRun::LIST_KEY)
            ?? throw new \LogicException(\sprintf('The list "%s" is not registered.', AiRun::LIST_KEY));

        $this->restHelper->initializeListBuilder($listBuilder, $fieldDescriptors);

        if ($request->query->getString('sortBy') === '') {
            $listBuilder->sort($fieldDescriptors['createdAt'], 'desc');
        }

        $list = new PaginatedRepresentation(
            $listBuilder->execute(),
            AiRun::RESOURCE_KEY,
            (int) $listBuilder->getCurrentPage(),
            (int) $listBuilder->getLimit(),
            (int) $listBuilder->count(),
        );

        return $this->json($list->toArray());
    }

    #[Route(path: '/ai-content/runs/{id}', name: self::DETAIL_ROUTE, methods: ['GET'])]
    public function getRun(string $id, Request $request): JsonResponse
    {
        $this->denyUnlessGranted(AiContentAdmin::USAGE_SECURITY_CONTEXT, PermissionTypes::VIEW);

        $run = $this->runRepository->findById($id) ?? throw new NotFoundHttpException(\sprintf('The run "%s" does not exist.', $id));

        return $this->json($this->runPresenter->toDetail($run, $request->query->getString('locale', 'en')));
    }
}
