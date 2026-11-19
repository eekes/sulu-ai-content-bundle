<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Functional;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Content\SnippetFinderInterface;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;

/**
 * The assistant finds snippets as its editor would: only with the view permission on snippets.
 */
final class SnippetPermissionsTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        $client = self::createAuthenticatedClient();
        $client->disableReboot();

        parent::setUp();

        $client->jsonRequest('POST', '/admin/api/snippets?locale=en', ['template' => 'faq', 'title' => 'Opening hours', 'answer' => 'Every day from seven.']);
        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent());
    }

    public function testAnEditorWhoMayViewSnippetsFindsThem(): void
    {
        self::removeTestUserRoles();
        self::giveTestUserRole('Writer', ['sulu.webspaces.website' => 127, SnippetAdmin::SECURITY_CONTEXT => 127]);

        self::assertSame(['Opening hours'], array_column($this->finderFor(self::testUser()->getId())->search('', 'en'), 'title'));
    }

    public function testAnEditorWhoMayNotViewSnippetsFindsNone(): void
    {
        self::removeTestUserRoles();
        self::giveTestUserRole('Writer', ['sulu.webspaces.website' => 127, SnippetAdmin::SECURITY_CONTEXT => 0]);

        self::assertSame([], $this->finderFor(self::testUser()->getId())->search('', 'en'));
    }

    public function testWithoutAnEditorNoSnippetsAreFound(): void
    {
        self::assertSame([], $this->finderFor(null)->search('', 'en'));
    }

    private function finderFor(?int $userId): SnippetFinderInterface
    {
        self::service(AssistantScope::class)->enter('en', 'website', $userId);

        return self::service(SnippetFinderInterface::class);
    }
}
