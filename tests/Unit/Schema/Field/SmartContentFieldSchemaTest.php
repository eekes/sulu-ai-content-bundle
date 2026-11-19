<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\SmartContentFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;
use Eekes\Sulu\AiContentBundle\Tests\Double\Fixtures;
use Eekes\Sulu\AiContentBundle\Tests\Double\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmartContentFieldSchemaTest extends TestCase
{
    private SmartContentFieldSchema $schema;
    private SchemaContext $context;

    protected function setUp(): void
    {
        $this->schema = new SmartContentFieldSchema(Fixtures::smartContentProviders(), Fixtures::taxonomyFinder(), Fixtures::pageFinder());
        $this->context = new SchemaContext('en');
    }

    public function testOnlyProvidersThatExistAreFilledIn(): void
    {
        self::assertTrue($this->schema->supports(new FieldDescription('children', 'smart_content', 'Children')));
        self::assertTrue($this->schema->supports(self::news()));
        self::assertFalse($this->schema->supports(new FieldDescription('people', 'smart_content', 'People', params: ['provider' => 'contacts'])));
        self::assertFalse($this->schema->supports(new FieldDescription('title', 'text_line', 'Title')));
    }

    public function testTheSchemaOffersWhatTheProviderOffers(): void
    {
        $pages = $this->schema->schema(new FieldDescription('children', 'smart_content', 'Children'), $this->context);
        self::assertSame(['dataSource', 'includeSubFolders', 'categories', 'categoryOperator', 'tags', 'tagOperator', 'sortBy', 'sortMethod', 'limitResult'], array_keys(Json::array($pages, 'properties')));
        self::assertSame(['published', 'title'], Json::at($pages, 'properties.sortBy.enum'));

        $articles = $this->schema->schema(self::news(), $this->context);
        self::assertSame(['categories', 'categoryOperator', 'tags', 'tagOperator', 'types', 'sortBy', 'sortMethod', 'limitResult', 'presentAs'], array_keys(Json::array($articles, 'properties')));
        self::assertSame(['blog', 'news'], Json::at($articles, 'properties.types.items.enum'));
        self::assertSame(['cards', 'list'], Json::at($articles, 'properties.presentAs.enum'));
        self::assertStringContainsString('fills itself', Json::string($articles, 'description'));
    }

    public function testFiltersAreCheckedAndStoredAsTheAdminStoresThem(): void
    {
        self::assertSame([
            'categories' => [1, 2],
            'categoryOperator' => 'and',
            'tags' => [5],
            'types' => ['news'],
            'sortBy' => 'published',
            'sortMethod' => 'desc',
            'limitResult' => 3,
            'presentAs' => 'cards',
        ], $this->schema->sanitize(self::news(), [
            'categories' => [1, '2', 2],
            'categoryOperator' => 'AND',
            'tags' => [5],
            'types' => ['news'],
            'sortBy' => 'published',
            'sortMethod' => 'DESC',
            'limitResult' => 3,
            'presentAs' => 'cards',
        ], $this->context));

        self::assertSame(
            ['dataSource' => Fixtures::PAGE_UUID, 'includeSubFolders' => true],
            $this->schema->sanitize(new FieldDescription('children', 'smart_content', 'Children'), ['dataSource' => Fixtures::PAGE_UUID, 'includeSubFolders' => true], $this->context),
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    #[DataProvider('invalidFilters')]
    public function testAWrongFilterIsExplainedToTheModel(string $provider, array $value, string $message): void
    {
        $field = $provider === 'articles' ? self::news() : new FieldDescription('children', 'smart_content', 'Children');

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage($message);

        $this->schema->sanitize($field, $value, $this->context);
    }

    /**
     * @return iterable<string, array{string, array<mixed>, string}>
     */
    public static function invalidFilters(): iterable
    {
        yield 'a made-up category' => ['articles', ['categories' => [1, 9]], 'Unknown category ids: 9. Use search_categories'];
        yield 'a tag by name' => ['articles', ['tags' => ['News']], '"tags" must be a list of ids, not names.'];
        yield 'an unknown tag' => ['articles', ['tags' => [6]], 'Unknown tag ids: 6. Use search_tags'];
        yield 'a type the provider does not have' => ['articles', ['types' => ['event']], '"types" must be one of: blog, news.'];
        yield 'a data source for articles' => ['articles', ['dataSource' => Fixtures::PAGE_UUID], 'The articles list has no "dataSource" filter.'];
        yield 'a made-up data source' => ['pages', ['dataSource' => 'nope'], 'Unknown page "nope" as data source.'];
        yield 'too many items' => ['pages', ['limitResult' => 500], '"limitResult" must be a number from 1 to 100.'];
        yield 'an unknown sorting' => ['pages', ['sortBy' => 'random'], '"sortBy" must be one of: published, title.'];
        yield 'a presentation the field does not have' => ['pages', ['presentAs' => 'cards'], 'The pages list has no "presentAs" filter.'];
        yield 'picked items' => ['pages', [Fixtures::PAGE_UUID], 'Expected an object with the filters of the list.'];
    }

    private static function news(): FieldDescription
    {
        return new FieldDescription('news', 'smart_content', 'News', params: ['provider' => 'articles', 'present_as' => ['cards' => 'Cards', 'list' => 'List']]);
    }
}
