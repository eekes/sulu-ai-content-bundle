<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Content;

/**
 * What a smart content provider lets an editor filter on, as plain data: the assistant fills in
 * exactly these filters and nothing else.
 */
final readonly class SmartContentDescription
{
    /**
     * @param null|string $dataSourceResourceKey the resource a data source is picked from ("pages" for pages and articles below a page), null without data source
     * @param list<array{value: string, label: string}> $types
     * @param list<array{value: string, label: string}> $sorting
     */
    public function __construct(
        public string $provider,
        public ?string $dataSourceResourceKey = null,
        public bool $tags = false,
        public bool $categories = false,
        public array $types = [],
        public array $sorting = [],
        public bool $limit = false,
    ) {
    }

    /**
     * A data source the assistant can fill in: a page, checked against the page tree. Other data
     * sources (media collections, contact folders) are left to the editor.
     */
    public function hasPageDataSource(): bool
    {
        return $this->dataSourceResourceKey === 'pages';
    }
}
