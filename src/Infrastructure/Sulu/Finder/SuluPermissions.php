<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder;

use Doctrine\ORM\QueryBuilder;
use Eekes\Sulu\AiContentBundle\Application\Content\WebspaceAccessInterface;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\SecurityBundle\AccessControl\AccessControlQueryEnhancerInterface;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authentication\UserRepositoryInterface;
use Sulu\Component\Security\Authorization\AccessControl\AccessControlManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Sulu\Page\Infrastructure\Sulu\Admin\PageAdmin;

/**
 * What an editor may view, as the Sulu admin decides it: the view permission on a security
 * context, and the permissions of an object that has its own (a page, a media collection).
 * Checked for a given user rather than for the logged-in one, because a run in a worker has none.
 */
final class SuluPermissions implements WebspaceAccessInterface
{
    /**
     * @param array<string, int> $permissions Sulu's permission masks by type
     */
    public function __construct(
        private readonly AccessControlManagerInterface $accessControlManager,
        private readonly AccessControlQueryEnhancerInterface $queryEnhancer,
        private readonly UserRepositoryInterface $userRepository,
        private readonly SystemStoreInterface $systemStore,
        private readonly array $permissions,
    ) {
    }

    /**
     * Whether the user may view what the security context guards. No user may view nothing.
     */
    public function canView(?int $userId, string $securityContext, ?string $locale = null): bool
    {
        $user = $this->user($userId);

        if ($user === null) {
            return false;
        }

        $condition = new SecurityCondition($securityContext, $locale, null, null, Admin::SULU_ADMIN_SECURITY_SYSTEM);

        return ($this->accessControlManager->getUserPermissions($condition, $user)[PermissionTypes::VIEW] ?? false) === true;
    }

    /**
     * The pages of a webspace, and with them its AI context.
     */
    public function canUseWebspace(?int $userId, string $webspaceKey, string $locale): bool
    {
        return $this->canView($userId, PageAdmin::getPageSecurityContext($webspaceKey), $locale);
    }

    /**
     * Leaves out the objects with permissions of their own that none of the user's roles may view.
     *
     * @param class-string $entityClass the class the permissions are kept for
     */
    public function restrict(QueryBuilder $queryBuilder, string $alias, ?int $userId, string $entityClass, string $idField = 'id'): void
    {
        $this->useAdminSystem();
        $this->queryEnhancer->enhance($queryBuilder, $this->user($userId), $this->viewMask(), $entityClass, $alias, $idField);
    }

    /**
     * Sulu's access control queries match the roles of the current security system. Outside an
     * admin request - in a worker, which runs the admin kernel - none is set, and they would match
     * the user's roles of no system at all.
     */
    public function useAdminSystem(): void
    {
        if ($this->systemStore->getSystem() === null) {
            $this->systemStore->setSystem(Admin::SULU_ADMIN_SECURITY_SYSTEM);
        }
    }

    /**
     * The view permission as Sulu's repositories take it.
     */
    public function viewMask(): int
    {
        return $this->permissions[PermissionTypes::VIEW] ?? 64;
    }

    public function user(?int $userId): ?UserInterface
    {
        if ($userId === null) {
            return null;
        }

        $user = $this->userRepository->findUserById($userId);

        return $user instanceof UserInterface ? $user : null;
    }
}
