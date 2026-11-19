<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Functional;

use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;
use Eekes\Sulu\AiContentBundle\Tests\Double\Json;
use Eekes\Sulu\AiContentBundle\Tests\Double\ModelScript;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The planner: several new pages planned in one conversation, then each written in a turn of
 * its own. Creating the pages is the admin's work, through Sulu's own API.
 */
final class PlannerApiTest extends FunctionalTestCase
{
    private KernelBrowser $client;
    private string $homepage;
    private string $services;

    protected function setUp(): void
    {
        $this->client = self::createAuthenticatedClient();
        $this->client->disableReboot();

        parent::setUp();

        (new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('sulu:page:initialize')))->execute([]);
        $this->client->jsonRequest('GET', '/admin/api/pages?locale=en&webspace=website&fields=id,title');
        $this->homepage = Json::string($this->json(), '_embedded.pages.0.id');
        $this->client->jsonRequest('POST', '/admin/api/pages?locale=en&webspace=website&parentId='.$this->homepage, ['template' => 'landing', 'title' => 'Our services', 'url' => '/our-services']);
        $this->services = Json::string($this->json(), 'id');
    }

    public function testAPlanIsCheckedAndNamedBeforeTheEditorSeesIt(): void
    {
        self::script()->reply(
            ModelScript::json(['type' => 'plan', 'message' => 'Three pages.', 'plan' => ['items' => [
                ['title' => 'Bread', 'template' => 'landing', 'parent' => $this->services, 'brief' => 'Our breads.'],
                ['title' => 'Cakes', 'template' => 'cakes', 'parent' => $this->services, 'brief' => 'Our cakes.'],
                ['title' => 'Catering', 'template' => 'default', 'parent' => 'made-up', 'brief' => 'Catering.'],
            ]]]),
            ModelScript::json(['type' => 'plan', 'message' => 'Three pages.', 'plan' => ['items' => [
                ['title' => 'Bread', 'template' => 'landing', 'parent' => $this->services, 'brief' => 'Our breads.'],
                ['title' => 'Cakes', 'template' => 'default', 'parent' => $this->services, 'brief' => 'Our cakes.'],
                ['title' => 'Catering', 'template' => 'default', 'parent' => $this->homepage, 'brief' => 'Catering.'],
            ]]]),
        );

        $conversation = $this->startConversation('plan:website');
        $turn = $this->sendTurn($conversation, ['action' => 'plan_pages', 'message' => 'Pages for our products']);

        self::assertSame('plan', Json::at($turn, 'reply.type'));
        // the run is stored as JSON in MySQL, which orders the keys its own way
        self::assertEquals([
            ['title' => 'Bread', 'template' => 'landing', 'templateTitle' => 'Landing page', 'parent' => $this->services, 'parentTitle' => 'Our services', 'brief' => 'Our breads.', 'kind' => null, 'key' => null],
            ['title' => 'Cakes', 'template' => 'default', 'templateTitle' => 'Default', 'parent' => $this->services, 'parentTitle' => 'Our services', 'brief' => 'Our cakes.', 'kind' => null, 'key' => null],
            ['title' => 'Catering', 'template' => 'default', 'templateTitle' => 'Default', 'parent' => $this->homepage, 'parentTitle' => 'Website', 'brief' => 'Catering.', 'kind' => null, 'key' => null],
        ], Json::at($turn, 'reply.plan'));
        self::assertSame([], Json::at($turn, 'reply.warnings'));

        $prompt = self::script()->lastPrompt();
        self::assertStringContainsString('plan item 2 ("Cakes"): the template "cakes" does not exist; use one of: default, landing.', $prompt);
        self::assertStringContainsString('plan item 3 ("Catering"): the page "made-up" does not exist', $prompt);
        self::assertStringContainsString('you plan several new pages of a website at once', $prompt);
        self::assertStringContainsString('  - Our services (/our-services, template landing, uuid '.$this->services.')', $prompt);
        self::assertStringNotContainsString('## JSON schema', $prompt, 'a plan needs no schema');
        self::assertSame(AiAction::PlanPages, self::runs()[0]->getAction());

        // the planner keeps its own history per webspace
        $this->client->jsonRequest('GET', '/admin/api/ai-content/conversations?formKey=page&resourceId=plan:website&locale=en');
        self::assertSame($conversation, Json::at($this->json(), 'conversations.0.id'));
    }

    public function testWhatStillDoesNotFitIsDroppedAndListed(): void
    {
        $plan = ['type' => 'plan', 'message' => 'Two.', 'plan' => [
            ['title' => 'Bread', 'template' => 'landing', 'parent' => $this->services, 'brief' => ''],
            ['title' => 'Nowhere', 'template' => 'landing', 'brief' => ''],
        ]];
        self::script()->reply(ModelScript::json($plan), ModelScript::json($plan));

        $turn = $this->sendTurn($this->startConversation('plan:website'), ['action' => 'plan_pages', 'message' => 'Plan']);

        self::assertSame(['Bread'], array_column(Json::array($turn, 'reply.plan'), 'title'));
        self::assertSame(['plan item 2 ("Nowhere"): it needs the uuid of the page it goes below as "parent".'], Json::at($turn, 'reply.warnings'));
    }

    public function testAPlanCanBeLimitedToTheTemplatesOfAnArticleList(): void
    {
        self::script()->reply(ModelScript::json(['type' => 'message', 'message' => 'Which subjects?']));

        $this->sendTurn($this->startConversation('plan:website'), ['action' => 'plan_pages', 'message' => 'Plan', 'templateKeys' => ['landing']]);

        $prompt = self::script()->lastPrompt();
        self::assertStringContainsString('- landing: Landing page', $prompt);
        self::assertStringNotContainsString('- default: Default', $prompt);
    }

    public function testHowFarAPlanGotIsKeptWithItsConversation(): void
    {
        $conversation = $this->startConversation('plan:website');
        $page = ['title' => 'Blog', 'template' => 'default', 'templateTitle' => 'Default', 'parent' => $this->homepage, 'parentTitle' => 'Website', 'brief' => 'The blog.', 'kind' => 'page', 'key' => 'blog'];
        $item = ['title' => 'Bread', 'template' => 'default', 'templateTitle' => 'Default', 'parent' => 'new:blog', 'parentTitle' => 'Blog', 'brief' => 'Breads.', 'kind' => null, 'key' => null];

        $this->client->jsonRequest('PATCH', '/admin/api/ai-content/conversations/'.$conversation, ['planProgress' => ['runId' => 'run-1', 'items' => [
            ['item' => $page, 'state' => ['state' => 'done', 'id' => 'page-0', 'url' => '/blog']],
            ['item' => $item, 'state' => ['state' => 'done', 'id' => 'page-1']],
            ['item' => ['title' => 'Cakes', 'brief' => ['not', 'text']], 'state' => ['state' => 'writing', 'progress' => 'Thinking']],
            ['item' => ['brief' => 'no title'], 'state' => ['state' => 'done']],
            'garbage',
        ]]]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/admin/api/ai-content/conversations/'.$conversation);
        self::assertEquals([
            ['item' => $page, 'state' => ['state' => 'done', 'id' => 'page-0', 'url' => '/blog']],
            ['item' => $item, 'state' => ['state' => 'done', 'id' => 'page-1']],
            ['item' => ['title' => 'Cakes', 'template' => null, 'templateTitle' => null, 'parent' => null, 'parentTitle' => null, 'brief' => null, 'kind' => null, 'key' => null], 'state' => ['state' => 'writing']],
        ], Json::at($this->json(), 'planProgress.run-1'));
        self::assertSame('plan:website', Json::at($this->json(), 'resourceId'), 'the progress does not link the conversation');

        $this->client->jsonRequest('PATCH', '/admin/api/ai-content/conversations/'.$conversation, ['planProgress' => ['items' => []]]);
        self::assertResponseStatusCodeSame(400);
        $this->client->jsonRequest('PATCH', '/admin/api/ai-content/conversations/'.$conversation, []);
        self::assertResponseStatusCodeSame(400);
    }

    public function testThePageSelectedInTheTreeIsWhereNewPagesGo(): void
    {
        self::script()->reply(ModelScript::json(['type' => 'message', 'message' => 'Which services?']));

        $this->sendTurn($this->startConversation('plan:website'), ['action' => 'plan_pages', 'message' => 'Pages for our services', 'selectedPage' => $this->services]);
        self::assertStringContainsString('The editor opened the planner on the page "Our services" (uuid '.$this->services.') in the page tree', self::script()->lastPrompt());

        // a page that does not exist is ignored
        self::script()->reply(ModelScript::json(['type' => 'message', 'message' => 'Which services?']));
        $this->sendTurn($this->startConversation('plan:website'), ['action' => 'plan_pages', 'message' => 'Pages', 'selectedPage' => 'made-up']);
        self::assertStringNotContainsString('opened the planner on the page', self::script()->lastPrompt());
    }

    public function testEveryPlannedPageIsWrittenInATurnOfItsOwn(): void
    {
        self::script()->reply(ModelScript::json(['type' => 'content', 'message' => 'Written.', 'content' => ['properties' => ['title' => 'Bread', 'intro' => 'Fresh every day.']]]));

        $turn = $this->sendTurn($this->startConversation(null), [
            'action' => 'write_planned',
            'message' => "Brief: Our breads.\n\nThe plan: Bread, Cakes.",
            'template' => 'landing',
            'content' => ['title' => 'Bread'],
        ]);

        self::assertSame('content', Json::at($turn, 'reply.type'));
        self::assertSame('Fresh every day.', Json::at($turn, 'reply.properties.intro'));
        self::assertStringContainsString('write this new page in full now', self::script()->lastPrompt());
        self::assertSame(AiAction::WritePlannedPage, self::runs()[0]->getAction());
    }

    public function testThePlanEditorGetsTheTemplatesWithTheirUrlFieldAndThePages(): void
    {
        $this->client->jsonRequest('GET', '/admin/api/ai-content/plan-options?formKey=page&locale=en&webspace=website');
        self::assertResponseIsSuccessful();

        self::assertSame([
            ['key' => 'default', 'title' => 'Default', 'route' => ['name' => 'url', 'type' => 'route', 'parts' => ['title']]],
            ['key' => 'landing', 'title' => 'Landing page', 'route' => ['name' => 'url', 'type' => 'route', 'parts' => ['title']]],
        ], Json::at($this->json(), 'templates'));
        self::assertSame(['Website', 'Our services'], array_column(Json::array($this->json(), 'pages'), 'title'));
        self::assertSame(1, Json::at($this->json(), 'pages.1.depth'));

        self::assertSame([], Json::at($this->json(), 'pageTemplates'), 'a page plan has no separate page');

        $this->client->jsonRequest('GET', '/admin/api/ai-content/plan-options?formKey=page&locale=en&webspace=website&templateKeys=landing');
        self::assertSame(['landing'], array_column(Json::array($this->json(), 'templates'), 'key'));

        // any other plan can create the page its items go below
        $this->client->jsonRequest('GET', '/admin/api/ai-content/plan-options?formKey=snippet&locale=en&webspace=website&templateKeys=faq');
        self::assertSame(['faq'], array_column(Json::array($this->json(), 'templates'), 'key'));
        self::assertSame(['default', 'landing'], array_column(Json::array($this->json(), 'pageTemplates'), 'key'));
        self::assertSame(['name' => 'url', 'type' => 'route', 'parts' => ['title']], Json::at($this->json(), 'pageTemplates.0.route'));
    }

    public function testAPlanOfAnotherKindCanCreateThePageItsItemsGoBelow(): void
    {
        self::script()->reply(
            // an overview planned as one of the items is sent back
            ModelScript::json(['type' => 'plan', 'message' => 'A blog.', 'plan' => ['items' => [
                ['title' => 'Blog', 'template' => 'landing', 'parent' => $this->homepage, 'brief' => 'The overview.'],
                ['title' => 'Bread', 'template' => 'faq', 'parent' => $this->homepage, 'brief' => 'Bread.'],
            ]]]),
            ModelScript::json(['type' => 'plan', 'message' => 'A blog.', 'plan' => ['items' => [
                ['kind' => 'page', 'key' => 'blog', 'title' => 'Blog', 'template' => 'landing', 'parent' => $this->homepage, 'brief' => 'The overview.'],
                ['title' => 'Bread', 'template' => 'faq', 'parent' => 'new:blog', 'brief' => 'Bread.'],
            ]]]),
        );

        $this->client->jsonRequest('POST', '/admin/api/ai-content/conversations', ['formKey' => 'snippet', 'locale' => 'en', 'webspace' => 'website', 'resourceId' => 'plan:website']);
        $turn = $this->sendTurn(Json::string($this->json(), 'id'), ['action' => 'plan_pages', 'message' => 'Write blog posts', 'templateKeys' => ['faq']]);

        self::assertEquals([
            ['title' => 'Blog', 'template' => 'landing', 'templateTitle' => 'Landing page', 'parent' => $this->homepage, 'parentTitle' => 'Website', 'brief' => 'The overview.', 'kind' => 'page', 'key' => 'blog'],
            ['title' => 'Bread', 'template' => 'faq', 'templateTitle' => 'FAQ item', 'parent' => 'new:blog', 'parentTitle' => 'Blog', 'brief' => 'Bread.', 'kind' => null, 'key' => null],
        ], Json::at($turn, 'reply.plan'));

        $prompt = self::script()->lastPrompt();
        self::assertStringContainsString('plan item 1 ("Blog"): "landing" is a page template; a page is a plan item of its own with "kind": "page" and a "key".', $prompt);
        self::assertStringContainsString("## Page templates\n\nOnly for the one page that holds the snippets.\n\n- default: Default\n- landing: Landing page", $prompt);
        self::assertStringContainsString('That overview is always a page, never a snippet. Never plan it with a snippet template.', $prompt);
    }

    private function startConversation(?string $resourceId): string
    {
        $this->client->jsonRequest('POST', '/admin/api/ai-content/conversations', ['formKey' => 'page', 'locale' => 'en', 'webspace' => 'website', 'resourceId' => $resourceId]);
        self::assertResponseStatusCodeSame(201);

        return Json::string($this->json(), 'id');
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<mixed>
     */
    private function sendTurn(string $conversation, array $request): array
    {
        $this->client->jsonRequest('POST', '/admin/api/ai-content/conversations/'.$conversation.'/turns', $request);
        self::assertResponseStatusCodeSame(202);
        $this->client->jsonRequest('GET', '/admin/api/ai-content/turns/'.Json::string($this->json(), 'id'));
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        return Json::decode((string) $this->client->getResponse()->getContent());
    }
}
