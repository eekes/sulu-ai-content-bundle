<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Prompt;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use Eekes\Sulu\AiContentBundle\Application\Prompt\SystemPromptBuilder;
use Eekes\Sulu\AiContentBundle\Tests\Double\Fixtures;
use PHPUnit\Framework\TestCase;

final class PlannerPromptTest extends TestCase
{
    public function testAnArticlePlannerAsksWhereTheOverviewPageGoesAndPlansItAsAPage(): void
    {
        $catalog = Catalog::fromArray(['templates' => ['page' => ['overview' => ['description' => 'Lists articles.']]]]);
        $prompt = (new SystemPromptBuilder(Fixtures::schemaBuilder()))->buildPlanner(
            'article',
            [new TypeDescription('article', 'Article', [])],
            $catalog,
            null,
            'en',
            pageTemplates: [new TypeDescription('overview', 'Overview', []), new TypeDescription('default', 'Default', [])],
        );

        self::assertStringContainsString('That overview is always a page, never an article.', $prompt);
        self::assertStringContainsString('on the first level of the website, next to the other main pages (below the homepage), or below another page', $prompt);
        self::assertStringContainsString('{"kind": "page", "key": "blog", "title": "...", "template": "<page template>", "parent": "<page uuid>", "brief": "..."}', $prompt);
        self::assertStringContainsString('"parent": "new:blog"', $prompt);
        self::assertStringContainsString("## Page templates\n\nOnly for the one page that holds the articles.\n\n- overview: Overview - Lists articles.\n- default: Default", $prompt);
        self::assertStringContainsString("## Templates\n\nEvery article of the plan uses one of these templates.\n\n- article: Article", $prompt);
    }

    public function testAPagePlannerKnowsNothingOfASeparatePage(): void
    {
        $prompt = (new SystemPromptBuilder(Fixtures::schemaBuilder()))->buildPlanner('page', [new TypeDescription('default', 'Default', [])], new Catalog(), null, 'en');

        self::assertStringNotContainsString('"kind": "page"', $prompt);
        self::assertStringNotContainsString('## Page templates', $prompt);
    }

    public function testTheProjectsInstructionsGoAlongWhenPlanning(): void
    {
        $prompt = (new SystemPromptBuilder(Fixtures::schemaBuilder(), 'Never plan pages about prices.'))->buildPlanner('page', [new TypeDescription('default', 'Default', [])], new Catalog(), null, 'en');

        self::assertStringContainsString("or the guidelines are in another language.\n\nNever plan pages about prices.\n\n## Reply format", $prompt);
    }

    public function testThePlannerIsToldTheLimitOfAPlan(): void
    {
        $prompt = (new SystemPromptBuilder(Fixtures::schemaBuilder(), maxPlanItems: 5))->buildPlanner('page', [new TypeDescription('default', 'Default', [])], new Catalog(), null, 'en');

        self::assertStringContainsString('Propose at most 5 pages.', $prompt);
    }
}
