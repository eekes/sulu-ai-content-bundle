<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Controller;

use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Permissions are checked by hand instead of through SecuredControllerInterface: that maps POST
 * to "add" and PUT to "edit", while using the assistant is a single "view" permission.
 */
abstract class AbstractAdminController extends AbstractController
{
    public function __construct(
        protected readonly SecurityCheckerInterface $securityChecker,
    ) {
    }

    protected function denyUnlessGranted(string $securityContext, string $permission): void
    {
        $this->securityChecker->checkPermission($securityContext, $permission);
    }

    protected function currentUserId(): ?int
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    /**
     * The language the current user works in, for texts the server translates for the admin.
     */
    protected function userLocale(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getLocale() : 'en';
    }

    protected static function locale(Request $request): string
    {
        $locale = $request->query->getString('locale');

        if ($locale === '') {
            throw new BadRequestHttpException('The "locale" query parameter is required.');
        }

        return $locale;
    }
}
