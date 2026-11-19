<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\IconFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\IconFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;
use Eekes\Sulu\AiContentBundle\Tests\Double\Json;
use PHPUnit\Framework\TestCase;

final class IconFieldSchemaTest extends TestCase
{
    public function testASmallSetIsListedALargeOneIsSearched(): void
    {
        $finder = new class implements IconFinderInterface {
            public function icons(string $iconSet): array
            {
                return match ($iconSet) {
                    'small' => ['leaf', 'phone'],
                    'large' => array_map(static fn (int $index) => 'icon-'.$index, range(1, IconFieldSchema::LIST_LIMIT + 1)),
                    default => [],
                };
            }
        };
        $schema = new IconFieldSchema($finder);
        $context = new SchemaContext('en');

        self::assertSame(['leaf', 'phone'], $schema->schema(self::field('small'), $context)['enum'] ?? null);
        self::assertArrayNotHasKey('enum', $schema->schema(self::field('large'), $context));
        self::assertStringContainsString('search_icons, icon set "large"', Json::string($schema->schema(self::field('large'), $context), 'description'));

        self::assertFalse($schema->supports(self::field('missing')), 'a set without icons is left to the editor');
        self::assertFalse($schema->supports(new FieldDescription('icon', 'single_icon_selection', 'Icon')), 'and so is a field without set');
    }

    private static function field(string $iconSet): FieldDescription
    {
        return new FieldDescription('icon', 'single_icon_selection', 'Icon', params: ['icon_set' => $iconSet]);
    }
}
