<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\PageTreeRouteFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;
use Eekes\Sulu\AiContentBundle\Tests\Double\Fixtures;
use Eekes\Sulu\AiContentBundle\Tests\Double\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageTreeRouteFieldSchemaTest extends TestCase
{
    private PageTreeRouteFieldSchema $schema;
    private FieldDescription $field;

    protected function setUp(): void
    {
        $this->schema = new PageTreeRouteFieldSchema(Fixtures::pageFinder());
        $this->field = new FieldDescription('url', 'page_tree_route', 'URL');
    }

    public function testTheModelOnlyPicksThePageTheUrlSitsBelow(): void
    {
        self::assertTrue($this->schema->supports($this->field));
        self::assertFalse($this->schema->supports(new FieldDescription('url', 'route', 'URL')));
        self::assertSame(['page'], array_keys(Json::array($this->schema->schema($this->field, new SchemaContext('en')), 'properties')));
    }

    #[DataProvider('pages')]
    public function testThePageGetsItsPathTheWaySuluStoresIt(mixed $value): void
    {
        self::assertSame(
            ['page' => ['uuid' => Fixtures::PAGE_UUID, 'path' => '/news']],
            $this->schema->sanitize($this->field, $value, new SchemaContext('en')),
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function pages(): iterable
    {
        yield 'as asked' => [['page' => Fixtures::PAGE_UUID]];
        yield 'with the shape of the form' => [['page' => ['uuid' => Fixtures::PAGE_UUID, 'path' => '/made-up'], 'suffix' => '/made-up']];
        yield 'just the uuid' => [Fixtures::PAGE_UUID];
    }

    public function testAPageWithoutUrlIsRefused(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('The page "nope" does not exist or has no URL in this language.');

        $this->schema->sanitize($this->field, ['page' => 'nope'], new SchemaContext('en'));
    }

    public function testNoPageIsRefused(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('Expected {"page": "<page uuid>"}.');

        $this->schema->sanitize($this->field, ['suffix' => '/my-article'], new SchemaContext('en'));
    }
}
