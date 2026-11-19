<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Catalog;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Catalog\Guide;
use PHPUnit\Framework\TestCase;

final class GuideTest extends TestCase
{
    public function testGeneratedTextIsNotEditedUntilAPersonChangesIt(): void
    {
        $guide = (new Guide(excludeFields: ['theme']))->withGeneratedText(new Guide('A hero', 'At the top'), 'abc');

        self::assertFalse($guide->isEdited());
        self::assertSame(['theme'], $guide->excludeFields);
        self::assertSame('abc', $guide->fingerprint);

        $edited = Guide::fromArray(['description' => 'A big hero'] + $guide->toArray());
        self::assertTrue($edited->isEdited());
    }

    public function testTextWithoutBookkeepingCountsAsWrittenByAPerson(): void
    {
        self::assertTrue(Guide::fromArray(['description' => 'Mine'])->isEdited());
        self::assertFalse(Guide::fromArray([])->isEdited());
    }

    public function testTheCatalogSurvivesARoundTrip(): void
    {
        $catalog = Catalog::fromArray([
            'exclude_fields' => ['url'],
            'templates' => ['page' => ['default' => ['description' => 'A page']]],
            'blocks' => ['hero' => ['description' => 'Hero', 'exclude_fields' => ['background']]],
        ]);

        self::assertEquals($catalog, Catalog::fromArray($catalog->toArray()));
        self::assertTrue($catalog->isExcluded('url'));
        self::assertTrue($catalog->isExcluded('background', 'hero'));
        self::assertFalse($catalog->isExcluded('background'));
        self::assertSame('A page', $catalog->template('page', 'default')?->description);
    }
}
