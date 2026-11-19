<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Schema;

use Eekes\Sulu\AiContentBundle\Application\Schema\PlaceholderFinder;
use PHPUnit\Framework\TestCase;

final class PlaceholderFinderTest extends TestCase
{
    public function testFindsPlaceholdersInEveryNestedText(): void
    {
        $found = (new PlaceholderFinder())->find([
            'title' => 'Since [year]',
            'blocks' => [['type' => 'text', '_ref' => 0, 'text' => '<p>Call us at <strong>[phone number]</strong> in {city}.</p>']],
            ['seo' => ['title' => 'Bakery since [year]']],
        ]);

        self::assertSame(['[year]', '[phone number]', '{city}'], $found);
    }

    public function testOrdinaryTextIsNotAPlaceholder(): void
    {
        self::assertSame([], (new PlaceholderFinder())->find([
            'text' => '<p>Arrays look like [] and a [x] is too short; <a href="https://example.com">links</a> are fine.</p>',
            'number' => 3,
        ]));
    }
}
