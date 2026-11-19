<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Functional;

use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;
use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;
use Eekes\Sulu\AiContentBundle\Domain\Model\TokenTotals;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Admin\AiContentAdmin;
use Eekes\Sulu\AiContentBundle\Tests\Double\Json;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The AI context tab, the usage log and what the admin is told: views, toolbar button, config.
 */
final class AdminApiTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createAuthenticatedClient();
        $this->client->disableReboot();

        parent::setUp();
    }

    public function testTheWebspaceContextIsEmptyUntilSavedAndKeptPerLocale(): void
    {
        $this->client->jsonRequest('GET', '/admin/api/ai-content/contexts/website?locale=en');
        self::assertResponseIsSuccessful();
        self::assertNull(Json::at($this->json(), 'siteDescription'));
        self::assertSame([], Json::at($this->json(), 'examplePages'));

        $this->client->jsonRequest('PUT', '/admin/api/ai-content/contexts/website?locale=en', [
            'siteDescription' => '  A bakery.  ',
            'toneOfVoice' => 'Warm',
            'wordsToAvoid' => '',
            'examplePages' => ['a-uuid', 5],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('A bakery.', Json::at($this->json(), 'siteDescription'));
        self::assertNull(Json::at($this->json(), 'wordsToAvoid'));
        self::assertSame(['a-uuid'], Json::at($this->json(), 'examplePages'));

        $this->client->jsonRequest('GET', '/admin/api/ai-content/contexts/website?locale=en');
        self::assertSame('Warm', Json::at($this->json(), 'toneOfVoice'));

        $this->client->jsonRequest('GET', '/admin/api/ai-content/contexts/website?locale=de');
        self::assertNull(Json::at($this->json(), 'toneOfVoice'), 'every locale has its own context');
    }

    public function testTheContextOfAnUnknownWebspaceIsNotFound(): void
    {
        $this->client->jsonRequest('GET', '/admin/api/ai-content/contexts/nope?locale=en');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheContextNeedsALocale(): void
    {
        $this->client->jsonRequest('GET', '/admin/api/ai-content/contexts/website');

        self::assertResponseStatusCodeSame(400);
    }

    public function testTheUsageLogListsRunsNewestFirstWithTheUser(): void
    {
        $this->createRun(AiAction::CreatePage, '-2 hours', new TokenTotals(1000, 100), 4500);
        $this->createRun(AiAction::Seo, '-1 hour', new TokenTotals(500, 50), null);

        $this->client->jsonRequest('GET', '/admin/api/ai-content/runs?locale=en&fields=id,createdAt,action,status,user,model,totalTokens,cost&page=1&limit=10');
        self::assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame(2, Json::at($data, 'total'));
        $rows = Json::array($data, '_embedded.eekes_ai_content_runs');
        self::assertSame(['seo', 'create_page'], [Json::at($rows, '0.action'), Json::at($rows, '1.action')]);
        self::assertEquals([550, 1100], [Json::at($rows, '0.totalTokens'), Json::at($rows, '1.totalTokens')]);
        self::assertNotEmpty(Json::at($rows, '0.user'));
    }

    public function testTheUsageLogCanBeFiltered(): void
    {
        $this->createRun(AiAction::CreatePage, '-2 hours', new TokenTotals(1000, 100), 4500);
        $this->createRun(AiAction::Seo, '-1 hour', new TokenTotals(500, 50), null);

        $this->client->jsonRequest('GET', '/admin/api/ai-content/runs?locale=en&fields=id,action&filter[action]=seo');
        self::assertResponseIsSuccessful();
        self::assertSame(1, Json::at($this->json(), 'total'));
    }

    public function testARunCanBeReadInFull(): void
    {
        $run = $this->createRun(AiAction::RewritePage, '-1 hour', new TokenTotals(1000, 100), 4500);

        $this->client->jsonRequest('GET', '/admin/api/ai-content/runs/'.$run->id.'?locale=en');
        self::assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame('rewrite_page', Json::at($data, 'action'));
        self::assertSame('Rework this page', Json::at($data, 'actionLabel'));
        self::assertSame('Succeeded', Json::at($data, 'statusLabel'));
        self::assertSame(0.0045, Json::at($data, 'usage.cost'));
        self::assertSame(1100, Json::at($data, 'usage.tokens.total'));
        self::assertSame('test-smart-1', Json::at($data, 'usage.model'));
        self::assertSame(['type' => 'message', 'message' => 'Hi'], Json::at($data, 'reply'));
        self::assertNotNull(Json::at($data, 'user'));
        self::assertNull(Json::at($data, 'conversation'));
    }

    public function testTheContextKeepsFactsAsAList(): void
    {
        $this->client->jsonRequest('PUT', '/admin/api/ai-content/contexts/website?locale=en', [
            'facts' => [
                ['type' => 'fact', 'question' => 'Company name?', 'answer' => 'Brood & Zo'],
                ['type' => 'fact', 'question' => 'Founded?', 'answer' => ''],
                ['type' => 'fact', 'question' => 'company  name', 'answer' => 'Brood en Zo'],
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame([['type' => 'fact', 'question' => 'company  name', 'answer' => 'Brood en Zo']], Json::at($this->json(), 'facts'));
    }

    public function testTheAdminConfigAddsTheButtonTheTabAndTheUsageLog(): void
    {
        $this->client->jsonRequest('GET', '/admin/config');
        self::assertResponseIsSuccessful();

        $config = $this->json();
        $bundleConfig = Json::array($config, 'eekes_ai_content');
        self::assertTrue(Json::at($bundleConfig, 'enabled'));
        self::assertSame([['key' => 'smart', 'label' => 'Smart'], ['key' => 'fast', 'label' => 'fast']], Json::at($bundleConfig, 'models'));
        self::assertSame('smart', Json::at($bundleConfig, 'defaultModel'));
        self::assertSame(['spent' => 0.0, 'limit' => 5.0], Json::at($bundleConfig, 'budget'));

        $routes = [];

        foreach (Json::array($config, 'sulu_admin.routes') as $route) {
            $routes[Json::string($route, 'name')] = $route;
        }

        self::assertArrayHasKey('eekes_ai_content.webspace_context', $routes);
        self::assertSame('sulu_page.webspaces', Json::at($routes['eekes_ai_content.webspace_context'], 'parent'));
        self::assertArrayHasKey('eekes_ai_content.runs_list', $routes);
        self::assertSame('eekes_ai_content.run_detail', Json::at($routes['eekes_ai_content.run_detail'], 'type'));

        $pageContent = Json::at($routes['sulu_page.page_edit_form.content'] ?? null, 'options.toolbarActions');
        self::assertContains('eekes_ai_content.assistant', array_column(Json::array($pageContent), 'type'));

        // the page tree gets its planner button in the admin JavaScript, not as a view
        self::assertArrayNotHasKey('eekes_ai_content.planner', $routes);
    }

    public function testWithoutTheAssistantPermissionNothingOffersIt(): void
    {
        // the page tree's planner button only has the admin config to go on
        self::getContainer()->set('sulu_security.security_checker', new class implements SecurityCheckerInterface {
            public function checkPermission(mixed $subject, mixed $permission): bool
            {
                return $this->hasPermission($subject, $permission);
            }

            public function hasPermission(mixed $subject, mixed $permission): bool
            {
                return $subject !== AiContentAdmin::ASSISTANT_SECURITY_CONTEXT;
            }
        });

        $this->client->jsonRequest('GET', '/admin/config');
        self::assertResponseIsSuccessful();

        self::assertFalse(Json::at($this->json(), 'eekes_ai_content.enabled'));
        $routes = array_column(Json::array($this->json(), 'sulu_admin.routes'), null, 'name');
        self::assertNotContains('eekes_ai_content.assistant', array_column(Json::array(Json::at($routes['sulu_page.page_edit_form.content'] ?? null, 'options.toolbarActions')), 'type'));
        self::assertArrayHasKey('eekes_ai_content.runs_list', $routes, 'the usage log has a permission of its own');
    }

    public function testTheFormsLoad(): void
    {
        foreach (['eekes_ai_content_webspace_context'] as $form) {
            $this->client->jsonRequest('GET', '/admin/metadata/form/'.$form.'?locale=en');
            self::assertResponseIsSuccessful($form);
        }

        $this->client->jsonRequest('GET', '/admin/metadata/list/eekes_ai_content_runs?locale=en');
        self::assertResponseIsSuccessful();
    }

    private function createRun(AiAction $action, string $when, TokenTotals $tokens, ?int $costMicros): AiRun
    {
        $run = new AiRun($action, 'smart', 'test-smart-1', 'EUR');
        $run->createdAt = new \DateTimeImmutable($when);
        $run->userId = self::getTestUserId();
        $run->webspaceKey = 'website';
        $run->locale = 'en';
        $run->addUsage($tokens, 1);
        $run->setCostMicros($costMicros);
        $run->succeed(['type' => 'message', 'message' => 'Hi']);

        self::entityManager()->persist($run);
        self::entityManager()->flush();

        return $run;
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        return Json::decode((string) $this->client->getResponse()->getContent());
    }
}
