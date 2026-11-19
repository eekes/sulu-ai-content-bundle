<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogFile;
use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;
use Eekes\Sulu\AiContentBundle\Tests\Double\ModelScript;
use Sulu\Bundle\SecurityBundle\Entity\Permission;
use Sulu\Bundle\SecurityBundle\Entity\Role;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\SecurityBundle\Entity\UserRole;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;

/**
 * Boots the test application - Sulu's own test kernel plus this bundle - against a real database,
 * with ModelScript as the model.
 *
 * The schema is built from the entity metadata rather than from a migration: the bundle ships no
 * migrations, because the project that installs it generates its own.
 */
abstract class FunctionalTestCase extends SuluTestCase
{
    private static bool $schemaCreated = false;

    protected function setUp(): void
    {
        if (!static::$booted) {
            self::bootKernel();
        }

        if (!self::$schemaCreated) {
            $entityManager = self::entityManager();
            $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
            $schemaTool = new SchemaTool($entityManager);
            $schemaTool->dropSchema($metadata);
            $schemaTool->createSchema($metadata);
            self::$schemaCreated = true;
        }

        static::purgeDatabase();
        self::script()->reset();

        $catalog = self::service(CatalogFile::class)->getPath();

        if (is_file($catalog)) {
            unlink($catalog);
        }

        // the assistant reads only the pages, media and snippets its editor may see
        self::giveTestUserRole('Editor', ['sulu.webspaces.website' => 127, 'sulu.media.collections' => 127, SnippetAdmin::SECURITY_CONTEXT => 127]);
    }

    /**
     * Gives the test user a role of the admin with these permissions by security context, in
     * every locale of the test webspace.
     *
     * @param array<string, int> $permissions
     */
    protected static function giveTestUserRole(string $name, array $permissions): Role
    {
        $entityManager = self::entityManager();
        $role = new Role();
        $role->setName($name);
        $role->setSystem('Sulu');
        $entityManager->persist($role);

        foreach ($permissions as $context => $mask) {
            $permission = new Permission();
            $permission->setContext($context);
            $permission->setPermissions($mask);
            $permission->setRole($role);
            $role->addPermission($permission);
            $entityManager->persist($permission);
        }

        $user = self::testUser();
        $userRole = new UserRole();
        $userRole->setUser($user);
        $userRole->setRole($role);
        $userRole->setLocale('["en","de"]');
        $user->addUserRole($userRole);
        $entityManager->persist($userRole);
        $entityManager->flush();

        return $role;
    }

    /**
     * Takes every role away from the test user, to give it others.
     */
    protected static function removeTestUserRoles(): void
    {
        $user = self::testUser();

        foreach ($user->getUserRoles() as $userRole) {
            $user->removeUserRole($userRole);
            self::entityManager()->remove($userRole);
        }

        self::entityManager()->flush();
    }

    protected static function testUser(): User
    {
        // created on first use by Sulu's test user provider
        self::getTestUser();
        $user = self::entityManager()->getRepository(User::class)->findOneBy(['username' => 'test']);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    protected static function entityManager(): EntityManagerInterface
    {
        return self::service(EntityManagerInterface::class);
    }

    protected static function script(): ModelScript
    {
        return self::service(ModelScript::class);
    }

    /**
     * @return list<AiRun>
     */
    protected static function runs(): array
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(AiRun::class)->findBy([], ['createdAt' => 'ASC']);
    }
}
