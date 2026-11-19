<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Functional;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Eekes\Sulu\AiContentBundle\Tests\Double\Json;
use Sulu\Bundle\SecurityBundle\Entity\AccessControl;
use Sulu\Bundle\SecurityBundle\Entity\Role;
use Sulu\Page\Domain\Model\Page;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The assistant reads pages as its editor would: only in a webspace the editor may view, and not
 * the pages that have permissions of their own the editor's roles lack.
 */
final class PagePermissionsTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    private string $public;

    private string $boardOnly;

    protected function setUp(): void
    {
        $this->client = self::createAuthenticatedClient();
        $this->client->disableReboot();

        parent::setUp();

        (new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('sulu:page:initialize')))->execute([]);
        $this->client->jsonRequest('GET', '/admin/api/pages?locale=en&webspace=website&fields=id,title');
        $homepage = Json::string(Json::decode((string) $this->client->getResponse()->getContent()), '_embedded.pages.0.id');
        $this->public = $this->createPage($homepage, 'Opening hours');
        $this->boardOnly = $this->createPage($homepage, 'Board minutes');
    }

    public function testAPageWithPermissionsOfItsOwnIsLeftOutForAnEditorWithoutThem(): void
    {
        $board = self::giveTestUserRole('Board', []);
        $this->restrictToRole($this->boardOnly, $board);
        // the test user is not on the board after all
        $user = self::testUser();

        foreach ($user->getUserRoles() as $userRole) {
            if ($userRole->getRole() === $board) {
                $user->removeUserRole($userRole);
                self::entityManager()->remove($userRole);
            }
        }

        self::entityManager()->flush();

        $finder = $this->finderFor(self::testUser()->getId());

        self::assertSame(['Opening hours'], $this->titles($finder->tree('en', 'website')));
        self::assertSame(['Opening hours'], $this->titles($finder->search('', 'en', 'website')));
        self::assertNull($finder->get($this->boardOnly, 'en'));
        self::assertNotNull($finder->get($this->public, 'en'));
        self::assertSame([$this->boardOnly], $finder->existing([$this->boardOnly]), 'a link to it is still checked against the page itself');
    }

    public function testAnEditorWithThePermissionsOfThePageSeesIt(): void
    {
        $board = self::giveTestUserRole('Board', []);
        $this->restrictToRole($this->boardOnly, $board);

        $finder = $this->finderFor(self::testUser()->getId());

        self::assertContains('Board minutes', $this->titles($finder->tree('en', 'website')));
        self::assertNotNull($finder->get($this->boardOnly, 'en'));
    }

    public function testAnEditorWhoMayNotViewTheWebspaceGetsNoPages(): void
    {
        $user = self::testUser();

        foreach ($user->getUserRoles() as $userRole) {
            $user->removeUserRole($userRole);
            self::entityManager()->remove($userRole);
        }

        self::entityManager()->flush();
        self::giveTestUserRole('Writer', ['sulu.webspaces.website' => 0]);

        $finder = $this->finderFor(self::testUser()->getId());

        self::assertSame([], $finder->tree('en', 'website'));
        self::assertSame([], $finder->search('', 'en', 'website'));
        self::assertNull($finder->get($this->public, 'en'));
    }

    public function testWithoutAnEditorNoPagesAreRead(): void
    {
        $finder = $this->finderFor(null);

        self::assertSame([], $finder->tree('en', 'website'));
        self::assertNull($finder->get($this->public, 'en'));
    }

    private function finderFor(?int $userId): PageFinderInterface
    {
        self::service(AssistantScope::class)->enter('en', 'website', $userId);

        return self::service(PageFinderInterface::class);
    }

    private function createPage(string $parent, string $title): string
    {
        $this->client->jsonRequest('POST', '/admin/api/pages?locale=en&webspace=website&parentId='.$parent, ['template' => 'landing', 'title' => $title, 'url' => '/'.strtolower(str_replace(' ', '-', $title))]);
        self::assertResponseStatusCodeSame(201);

        return Json::string(Json::decode((string) $this->client->getResponse()->getContent()), 'id');
    }

    private function restrictToRole(string $pageUuid, Role $role): void
    {
        $accessControl = new AccessControl();
        $accessControl->setRole($role);
        $accessControl->setPermissions(127);
        $accessControl->setEntityClass(Page::class);
        $accessControl->setEntityId($pageUuid);
        self::entityManager()->persist($accessControl);
        self::entityManager()->flush();
    }

    /**
     * @param list<array{title: string}> $pages
     *
     * @return list<string>
     */
    private function titles(array $pages): array
    {
        // the pages of this test, without the homepage
        return array_values(array_filter(array_column($pages, 'title'), static fn (string $title) => \in_array($title, ['Opening hours', 'Board minutes'], true)));
    }
}
