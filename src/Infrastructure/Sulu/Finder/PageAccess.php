<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder;

use Doctrine\ORM\QueryBuilder;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\SecurityBundle\AccessControl\AccessControlQueryEnhancerInterface;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authentication\UserRepositoryInterface;
use Sulu\Component\Security\Authorization\AccessControl\AccessControlManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Infrastructure\Sulu\Admin\PageAdmin;

/**
 * Which pages an editor may view, as the Sulu admin decides it: the view permission on the
 * webspace, and the permissions of a page that has its own. Checked for a given user rather than
 * for the logged-in one, because a run in a worker has none.
 */
final class PageAccess
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
     * Whether the user may view the pages of the webspace. The permissions of a page that has its
     * own come from restrict().
     */
    public function canView(?int $userId, string $webspaceKey, string $locale): bool
    {
        $user = $this->user($userId);

        if ($user === null) {
            return false;
        }

        $condition = new SecurityCondition(
            PageAdmin::getPageSecurityContext($webspaceKey),
            $locale,
            null,
            null,
            Admin::SULU_ADMIN_SECURITY_SYSTEM,
        );

        return ($this->accessControlManager->getUserPermissions($condition, $user)[PermissionTypes::VIEW] ?? false) === true;
    }

    /**
     * Leaves out the pages with permissions of their own that none of the user's roles may view.
     */
    public function restrict(QueryBuilder $queryBuilder, string $pageAlias, ?int $userId): void
    {
        // outside an admin request - in a worker, which runs the admin kernel - no system is set,
        // and Sulu would match the user's roles of no system at all
        if ($this->systemStore->getSystem() === null) {
            $this->systemStore->setSystem(Admin::SULU_ADMIN_SECURITY_SYSTEM);
        }

        $this->queryEnhancer->enhance($queryBuilder, $this->user($userId), $this->permissions[PermissionTypes::VIEW] ?? 64, Page::class, $pageAlias, 'uuid');
    }

    private function user(?int $userId): ?UserInterface
    {
        if ($userId === null) {
            return null;
        }

        $user = $this->userRepository->findUserById($userId);

        return $user instanceof UserInterface ? $user : null;
    }
}
