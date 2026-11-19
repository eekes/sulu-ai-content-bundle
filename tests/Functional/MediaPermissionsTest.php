<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Functional;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Content\MediaFinderInterface;
use Eekes\Sulu\AiContentBundle\Tests\Double\Json;
use Sulu\Bundle\MediaBundle\DataFixtures\ORM\LoadCollectionTypes;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\SecurityBundle\Entity\AccessControl;
use Sulu\Bundle\SecurityBundle\Entity\Role;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The assistant finds media as its editor would: only with the view permission on media, and not
 * in a collection with permissions of its own that none of the editor's roles may view.
 */
final class MediaPermissionsTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    private int $boardCollection;

    protected function setUp(): void
    {
        $this->client = self::createAuthenticatedClient();
        $this->client->disableReboot();

        parent::setUp();

        (new LoadCollectionTypes())->load(self::entityManager());
        $this->upload($this->collection('Shop'), 'Shop photo');
        $this->boardCollection = $this->collection('Board');
        $this->upload($this->boardCollection, 'Board photo');
    }

    public function testMediaOfACollectionTheEditorMayNotViewAreLeftOut(): void
    {
        $board = self::giveTestUserRole('Board', []);
        $this->restrictToRole($this->boardCollection, $board);
        // the test user is not on the board after all
        self::removeTestUserRoles();
        self::giveTestUserRole('Shop editor', ['sulu.webspaces.website' => 127, 'sulu.media.collections' => 127]);

        self::assertSame(['Shop photo'], $this->titles($this->finderFor(self::testUser()->getId())->search('photo', 'en')));
    }

    public function testAnEditorWithThePermissionsOfTheCollectionFindsItsMedia(): void
    {
        $this->restrictToRole($this->boardCollection, self::giveTestUserRole('Board', []));

        self::assertSame(['Board photo', 'Shop photo'], $this->titles($this->finderFor(self::testUser()->getId())->search('photo', 'en')));
    }

    public function testAnEditorWhoMayNotViewMediaFindsNone(): void
    {
        self::removeTestUserRoles();
        self::giveTestUserRole('Writer', ['sulu.webspaces.website' => 127, 'sulu.media.collections' => 0]);

        self::assertSame([], $this->finderFor(self::testUser()->getId())->search('photo', 'en'));
    }

    public function testWithoutAnEditorNoMediaAreFound(): void
    {
        self::assertSame([], $this->finderFor(null)->search('photo', 'en'));
    }

    private function finderFor(?int $userId): MediaFinderInterface
    {
        self::service(AssistantScope::class)->enter('en', 'website', $userId);

        return self::service(MediaFinderInterface::class);
    }

    private function collection(string $title): int
    {
        $this->client->jsonRequest('POST', '/admin/api/collections?locale=en', ['title' => $title, 'type' => ['id' => 1]]);
        self::assertResponseIsSuccessful();
        $id = Json::at(Json::decode((string) $this->client->getResponse()->getContent()), 'id');
        self::assertIsInt($id);

        return $id;
    }

    private function upload(int $collection, string $title): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'media');
        // a 1x1 PNG
        file_put_contents($file, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        $this->client->request('POST', '/admin/api/media?locale=en&collection='.$collection, ['title' => $title], ['fileVersion' => new UploadedFile($file, 'photo.png', 'image/png', null, true)]);
        self::assertResponseIsSuccessful();
    }

    private function restrictToRole(int $collection, Role $role): void
    {
        $accessControl = new AccessControl();
        $accessControl->setRole($role);
        $accessControl->setPermissions(127);
        $accessControl->setEntityClass(Collection::class);
        $accessControl->setEntityId($collection);
        self::entityManager()->persist($accessControl);
        self::entityManager()->flush();
    }

    /**
     * @param list<array{title: string}> $media
     *
     * @return list<string>
     */
    private function titles(array $media): array
    {
        $titles = array_column($media, 'title');
        sort($titles);

        return $titles;
    }
}
