<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Content;

/**
 * What the assistant may read of the existing pages: enough to link to them and to learn from
 * the example pages. Replace it to limit or widen what the assistant sees.
 */
interface PageFinderInterface
{
    /**
     * Pages whose title contains the query, newest first.
     *
     * @return list<array{uuid: string, title: string, template: null|string}>
     */
    public function search(string $query, string $locale, ?string $webspaceKey, int $limit = 10): array;

    /**
     * The pages of a webspace in tree order - what the assistant knows about the site without
     * asking. At most $limit pages; the depth starts at 0 for the homepage. "holds" counts what
     * else has its URL below the page, by resource key - articles below a news page, for example.
     *
     * @return list<array{uuid: string, title: string, template: null|string, url: null|string, depth: int, holds: array<string, int>}>
     */
    public function tree(string $locale, string $webspaceKey, int $limit = 150): array;

    /**
     * The URL of a page in a locale, null when it has none there.
     */
    public function url(string $uuid, string $locale): ?string;

    /**
     * The draft content of one page, or null when it does not exist in that locale.
     *
     * @return null|array{uuid: string, title: string, template: null|string, content: array<string, mixed>}
     */
    public function get(string $uuid, string $locale): ?array;

    /**
     * The uuids of the given list that exist.
     *
     * @param list<string> $uuids
     *
     * @return list<string>
     */
    public function existing(array $uuids): array;
}
