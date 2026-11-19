<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Assistant;

use Eekes\Sulu\AiContentBundle\Application\Assistant\PlanChecker;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use Eekes\Sulu\AiContentBundle\Tests\Double\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * An article plan can hold the one page its articles go below - a blog or news overview - but
 * that page is always a page: never an article, and only where the page tree allows it.
 */
final class PlanCheckerTest extends TestCase
{
    public function testAnArticlePlanCanCreateThePageItsArticlesGoBelow(): void
    {
        [$plan, $errors] = $this->check([
            self::item('Blog', 'overview', Fixtures::PAGE_UUID, kind: 'page', key: 'blog'),
            self::item('Slow fermentation', 'article', 'new:blog'),
            self::item('A day at the bakery', 'article', Fixtures::PAGE_UUID),
        ]);

        self::assertSame([], $errors);
        self::assertSame(['page', null, null], array_column($plan, 'kind'));
        self::assertSame(['Overview', 'Article', 'Article'], array_column($plan, 'templateTitle'));
        self::assertSame(['News', 'Blog', 'News'], array_column($plan, 'parentTitle'));
        self::assertSame('new:blog', $plan[1]['parent']);
    }

    public function testAnOverviewIsNeverAnArticle(): void
    {
        [$plan, $errors] = $this->check([
            self::item('Blog', 'overview', Fixtures::PAGE_UUID),
            self::item('Archive', 'article', Fixtures::PAGE_UUID, kind: 'page', key: 'archive'),
        ]);

        self::assertSame([], $plan);
        self::assertSame([
            'plan item 1 ("Blog"): "overview" is a page template; a page is a plan item of its own with "kind": "page" and a "key".',
            'plan item 2 ("Archive"): the page template "article" does not exist; use one of: overview.',
        ], $errors);
    }

    public function testThePageNeedsAKeyAPlaceInThePageTreeAndComesAlone(): void
    {
        [, $errors] = $this->check([
            self::item('Blog', 'overview', null, kind: 'page'),
            self::item('News', 'overview', 'made-up', kind: 'page', key: 'news'),
        ]);

        self::assertSame([
            'plan item 1 ("Blog"): a page needs a "key" its articles refer to as "new:<key>"; it needs the uuid of the page it goes below as "parent".',
            'plan item 2 ("News"): the page "made-up" does not exist; use a page of the site structure; plan at most one page.',
        ], $errors);
    }

    public function testAPlanLongerThanTheLimitGoesBackAndIsCutToTheLimit(): void
    {
        [$plan, $errors] = $this->check([
            self::item('Blog', 'overview', Fixtures::PAGE_UUID, kind: 'page', key: 'blog'),
            self::item('One', 'article', 'new:blog'),
            self::item('Two', 'article', 'new:blog'),
            self::item('Three', 'article', 'new:blog'),
        ], maxItems: 2);

        // the page the articles go below does not count
        self::assertSame(['Blog', 'One', 'Two'], array_column($plan, 'title'));
        self::assertSame(['the plan has 3 articles; plan at most 2: leave out the least important ones, or ask the editor which to drop.'], $errors);
    }

    public function testAPlanWithinTheLimitIsKept(): void
    {
        [$plan, $errors] = $this->check([
            self::item('One', 'article', Fixtures::PAGE_UUID),
            self::item('Two', 'article', Fixtures::PAGE_UUID),
        ], maxItems: 2);

        self::assertSame([], $errors);
        self::assertCount(2, $plan);
    }

    public function testAnArticleCanOnlyGoBelowANewPageOfThePlan(): void
    {
        [$plan, $errors] = $this->check([
            self::item('Slow fermentation', 'article', 'new:blog'),
        ]);

        self::assertSame([], $plan);
        self::assertSame(['plan item 1 ("Slow fermentation"): "new:blog" is not a page of this plan; plan that page first with "kind": "page" and "key": "blog", or use an existing page.'], $errors);
    }

    public function testAPagePlanHasNoSeparatePages(): void
    {
        [$plan, $errors] = (new PlanChecker(Fixtures::pageFinder()))->check(
            [self::item('Bread', 'overview', Fixtures::PAGE_UUID, kind: 'page', key: 'bread')],
            'page',
            'en',
            [self::overview()],
            [],
            [],
        );

        self::assertSame([], $errors);
        self::assertNull($plan[0]['kind'], 'every item of a page plan is a page already');
    }

    /**
     * @param list<array{title: string, template: string, templateTitle: string, parent: null|string, parentTitle: null|string, brief: string, kind: null|string, key: null|string}> $items
     *
     * @return array{0: list<array{title: string, template: string, templateTitle: string, parent: null|string, parentTitle: null|string, brief: string, kind: null|string, key: null|string}>, 1: list<string>}
     */
    private function check(array $items, int $maxItems = 12): array
    {
        $siteStructure = [['uuid' => Fixtures::PAGE_UUID, 'title' => 'News', 'template' => 'overview', 'url' => '/news', 'depth' => 1, 'holds' => []]];

        return (new PlanChecker(Fixtures::pageFinder(), $maxItems))->check($items, 'article', 'en', [new TypeDescription('article', 'Article', [])], [self::overview()], $siteStructure);
    }

    private static function overview(): TypeDescription
    {
        return new TypeDescription('overview', 'Overview', []);
    }

    /**
     * @return array{title: string, template: string, templateTitle: string, parent: null|string, parentTitle: null|string, brief: string, kind: null|string, key: null|string}
     */
    private static function item(string $title, string $template, ?string $parent, ?string $kind = null, ?string $key = null): array
    {
        return ['title' => $title, 'template' => $template, 'templateTitle' => $template, 'parent' => $parent, 'parentTitle' => null, 'brief' => '', 'kind' => $kind, 'key' => $key];
    }
}
