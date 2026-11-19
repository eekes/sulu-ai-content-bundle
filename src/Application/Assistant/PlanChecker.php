<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant;

use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;

/**
 * Checks a plan before the editor sees it: every template exists, every item goes below a page
 * that exists, and it holds no more items than configured - each one is written by a model call of
 * its own. The problems are written for the model, which gets one round to fix them; a plan that
 * is still too long after that is cut to the limit.
 *
 * A plan of articles (or anything else that is not a page) may hold one page: the overview its
 * items go below when it does not exist yet, such as a blog page. It is an item with "kind":
 * "page" and a "key"; the other items refer to it as "new:<key>". An overview is always a page,
 * so a page template on any other item is a mistake the model has to fix.
 *
 * @phpstan-type PlanItem array{title: string, template: string, templateTitle: string, parent: null|string, parentTitle: null|string, brief: string, kind: null|string, key: null|string}
 */
final readonly class PlanChecker
{
    public const string KIND_PAGE = 'page';
    public const string NEW_PREFIX = 'new:';

    public function __construct(
        private PageFinderInterface $pageFinder,
        private int $maxItems = 12,
    ) {
    }

    /**
     * @param list<PlanItem> $items
     * @param list<TypeDescription> $templates the templates of the form the plan is for
     * @param list<TypeDescription> $pageTemplates the page templates, for the overview of a plan that is not about pages
     * @param list<array{uuid: string, title: string, template: null|string, url: null|string, depth: int, holds: array<string, int>}> $siteStructure
     *
     * @return array{0: list<PlanItem>, 1: list<string>}
     */
    public function check(array $items, string $formKey, string $locale, array $templates, array $pageTemplates, array $siteStructure): array
    {
        $templateTitles = self::titles($templates);
        $pageTemplateTitles = $formKey === 'page' ? [] : self::titles($pageTemplates);
        $pageTitles = array_column($siteStructure, 'title', 'uuid');
        $problems = [];
        // the pages of the plan the other items may go below, by key
        $newPages = [];
        $pageCount = 0;

        foreach ($items as $index => $item) {
            $item['kind'] = $formKey !== 'page' && $item['kind'] === self::KIND_PAGE ? self::KIND_PAGE : null;
            $item['key'] = $item['kind'] === null ? null : $item['key'];
            $items[$index] = $item;

            if ($item['kind'] === null) {
                continue;
            }

            $problems[$index] = $this->pageProblems($item, $locale, $formKey, $pageTemplateTitles);

            if (++$pageCount > 1) {
                $problems[$index][] = 'plan at most one page';
            }

            if ($problems[$index] === [] && $item['key'] !== null) {
                $newPages[$item['key']] = $item['title'];
            }
        }

        $plan = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $itemProblems = $item['kind'] === null
                ? $this->itemProblems($item, $locale, $formKey, $templateTitles, $pageTemplateTitles, $newPages)
                : $problems[$index] ?? [];

            if ($itemProblems !== []) {
                $errors[] = \sprintf('plan item %d ("%s"): %s.', $index + 1, $item['title'], implode('; ', $itemProblems));

                continue;
            }

            $parent = (string) $item['parent'];
            $item['templateTitle'] = $item['kind'] === null ? $templateTitles[$item['template']] : $pageTemplateTitles[$item['template']];
            $item['parentTitle'] = str_starts_with($parent, self::NEW_PREFIX)
                ? $newPages[substr($parent, \strlen(self::NEW_PREFIX))]
                : $pageTitles[$parent] ?? $this->pageFinder->get($parent, $locale)['title'] ?? null;
            $plan[] = $item;
        }

        return $this->limit($plan, $errors, \count(array_filter($items, static fn (array $item) => $item['kind'] === null)), $formKey);
    }

    /**
     * Keeps the first items up to the limit. The one page of a plan that is not about pages does
     * not count: it is what the items go below.
     *
     * @param list<PlanItem> $plan
     * @param list<string> $errors
     *
     * @return array{0: list<PlanItem>, 1: list<string>}
     */
    private function limit(array $plan, array $errors, int $planned, string $formKey): array
    {
        if ($planned <= $this->maxItems) {
            return [$plan, $errors];
        }

        $errors[] = \sprintf('the plan has %d %ss; plan at most %d: leave out the least important ones, or ask the editor which to drop.', $planned, $formKey, $this->maxItems);
        $kept = [];
        $items = 0;

        foreach ($plan as $item) {
            if ($item['kind'] === null && ++$items > $this->maxItems) {
                continue;
            }

            $kept[] = $item;
        }

        return [$kept, $errors];
    }

    /**
     * @param PlanItem $item
     * @param array<string, string> $pageTemplateTitles
     *
     * @return list<string>
     */
    private function pageProblems(array $item, string $locale, string $formKey, array $pageTemplateTitles): array
    {
        $problems = [];

        if (!isset($pageTemplateTitles[$item['template']])) {
            $problems[] = \sprintf('the page template "%s" does not exist; use one of: %s', $item['template'], implode(', ', array_keys($pageTemplateTitles)));
        }

        if ($item['key'] === null) {
            $problems[] = \sprintf('a page needs a "key" its %ss refer to as "new:<key>"', $formKey);
        }

        if ($item['parent'] === null) {
            $problems[] = 'it needs the uuid of the page it goes below as "parent"';
        } elseif ($this->pageFinder->url($item['parent'], $locale) === null && $this->pageFinder->existing([$item['parent']]) === []) {
            $problems[] = \sprintf('the page "%s" does not exist; use a page of the site structure', $item['parent']);
        }

        return $problems;
    }

    /**
     * @param PlanItem $item
     * @param array<string, string> $templateTitles
     * @param array<string, string> $pageTemplateTitles
     * @param array<string, string> $newPages
     *
     * @return list<string>
     */
    private function itemProblems(array $item, string $locale, string $formKey, array $templateTitles, array $pageTemplateTitles, array $newPages): array
    {
        $problems = [];

        if (isset($pageTemplateTitles[$item['template']]) && !isset($templateTitles[$item['template']])) {
            $problems[] = \sprintf('"%s" is a page template; a page is a plan item of its own with "kind": "page" and a "key"', $item['template']);
        } elseif (!isset($templateTitles[$item['template']])) {
            $problems[] = \sprintf('the template "%s" does not exist; use one of: %s', $item['template'], implode(', ', array_keys($templateTitles)));
        }

        $parent = $item['parent'];

        if ($parent === null) {
            $problems[] = 'it needs the uuid of the page it goes below as "parent"';
        } elseif (str_starts_with($parent, self::NEW_PREFIX)) {
            $key = substr($parent, \strlen(self::NEW_PREFIX));

            if ($formKey === 'page' || !isset($newPages[$key])) {
                $problems[] = \sprintf('"%s" is not a page of this plan; plan that page first with "kind": "page" and "key": "%s", or use an existing page', $parent, $key);
            }
        } elseif ($this->pageFinder->url($parent, $locale) === null && ($formKey !== 'page' || $this->pageFinder->existing([$parent]) === [])) {
            $problems[] = \sprintf('the page "%s" does not exist or has no URL in this language; use a page of the site structure', $parent);
        }

        return $problems;
    }

    /**
     * @param list<TypeDescription> $templates
     *
     * @return array<string, string>
     */
    private static function titles(array $templates): array
    {
        $titles = [];

        foreach ($templates as $template) {
            $titles[$template->key] = $template->title;
        }

        return $titles;
    }
}
