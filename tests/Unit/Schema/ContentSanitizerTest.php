<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Schema;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Catalog\Guide;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;
use Eekes\Sulu\AiContentBundle\Tests\Double\Fixtures;
use Eekes\Sulu\AiContentBundle\Tests\Double\Json;
use PHPUnit\Framework\TestCase;

final class ContentSanitizerTest extends TestCase
{
    public function testValidContentPassesCleanedUp(): void
    {
        $result = Fixtures::sanitizer()->sanitize('page', Fixtures::template(), [
            'title' => "  Fresh\n bread <b>now</b> ",
            'newsletter' => true,
            'related' => [Fixtures::PAGE_UUID],
            'blocks' => [
                ['type' => 'hero', '_ref' => 2, 'title' => 'Welcome', 'image' => ['id' => 7], 'background' => 'dark', 'button' => ['provider' => 'page', 'href' => Fixtures::PAGE_UUID, 'title' => 'More']],
                ['type' => 'faq', 'questions' => [['type' => 'question', 'question' => 'Why?', 'answer' => '<p onclick="x()">Because <a href="javascript:alert(1)">so</a></p><script>bad()</script>']]],
            ],
        ], new Catalog(), new SchemaContext('en'));

        self::assertSame([], $result->errors);
        self::assertSame('Fresh bread now', $result->data['title']);
        self::assertTrue($result->data['newsletter']);
        self::assertSame([Fixtures::PAGE_UUID], $result->data['related']);
        self::assertSame([
            ['type' => 'hero', '_ref' => 2, 'title' => 'Welcome', 'image' => ['id' => 7], 'background' => 'dark', 'button' => ['provider' => 'page', 'href' => Fixtures::PAGE_UUID, 'locale' => 'en', 'title' => 'More']],
            ['type' => 'faq', 'questions' => [['type' => 'question', 'question' => 'Why?', 'answer' => '<p>Because <a>so</a></p>']]],
        ], $result->data['blocks']);
    }

    public function testEveryProblemIsReportedForTheModelAndTheRestIsKept(): void
    {
        $result = Fixtures::sanitizer()->sanitize('page', Fixtures::template(), [
            'title' => 'Ok',
            'url' => '/hack',
            'teaser' => ['anything'],
            'unknown' => 'x',
            'related' => ['made-up'],
            'blocks' => [
                ['type' => 'hero', 'title' => 'A', 'image' => ['id' => 99], 'background' => 'neon'],
                ['type' => 'carousel'],
                ['type' => 'faq', 'questions' => [['type' => 'question', 'question' => ['not', 'text']]]],
                ['type' => 'hero', 'title' => 'C'],
                ['type' => 'hero', 'title' => 'D'],
            ],
        ], new Catalog(), new SchemaContext('en'));

        self::assertSame([
            'url: there is no such field to fill in.',
            'teaser: there is no such field to fill in.',
            'unknown: there is no such field to fill in.',
            'related: Unknown page uuids: made-up. Use search_pages to find real ones.',
            'blocks[0].image: Unknown media id. Use an id from search_media or leave the field out.',
            'blocks[0].background: Expected one of: light, dark.',
            'blocks[1]: unknown block type "carousel". Allowed: hero, faq.',
            'blocks[2].questions[0].question: Expected a string.',
            'blocks[2].questions[0].answer: "Answer" is required. Fill it in. If you cannot fill it in properly - for example there is no page to link to - ask the editor for it with a question, or choose another block.',
            'blocks: at most 3 blocks.',
        ], $result->errors);
        self::assertSame('Ok', $result->data['title']);
        self::assertSame(['type' => 'hero', 'title' => 'A'], Json::at($result->data, 'blocks.0'));
        self::assertCount(3, Json::array($result->data, 'blocks'));
    }

    public function testExcludedFieldsCannotBeWritten(): void
    {
        $catalog = new Catalog(['newsletter'], [], ['hero' => new Guide(excludeFields: ['background'])]);

        $result = Fixtures::sanitizer()->sanitize('page', Fixtures::template(), [
            'newsletter' => false,
            'blocks' => [['type' => 'hero', 'title' => 'A', 'background' => 'dark']],
        ], $catalog, new SchemaContext('en'));

        self::assertSame(['newsletter: there is no such field to fill in.', 'blocks[0].background: there is no such field to fill in.'], $result->errors);
        self::assertSame(['blocks' => [['type' => 'hero', 'title' => 'A']]], $result->data);
    }

    public function testTheSchemaOnlyOffersWritableFields(): void
    {
        $catalog = new Catalog([], ['page' => ['default' => new Guide(excludeFields: ['related'])]]);
        $schema = Fixtures::schemaBuilder()->build('page', Fixtures::template(), $catalog, new SchemaContext('en'));

        // "teaser" lists a provider the project does not have
        self::assertSame(['title', 'blocks', 'newsletter', 'news', 'children'], array_keys($schema['properties']));
        self::assertSame('Title (required). Plain text on one line: no HTML, no Markdown, no links.', $schema['properties']['title']['description']);

        $hero = Json::array($schema, 'properties.blocks.items.oneOf.0');
        self::assertSame(['type' => 'object', 'title' => 'Hero'], array_intersect_key($hero, ['type' => 1, 'title' => 1]));
        self::assertSame(['type', 'title'], Json::at($hero, 'required'));
        self::assertSame(['const' => 'hero'], Json::at($hero, 'properties.type'));
        self::assertArrayHasKey('_ref', Json::array($hero, 'properties'));
        self::assertSame(['light', 'dark'], Json::at($hero, 'properties.background.enum'));
        self::assertSame(3, Json::at($schema, 'properties.blocks.maxItems'));
    }

    public function testRequiredFieldsOfNewBlocksMustBeFilled(): void
    {
        $result = Fixtures::sanitizer()->sanitize('page', Fixtures::template(), [
            'blocks' => [
                ['type' => 'hero', 'background' => 'dark'],
                ['type' => 'faq', 'questions' => [['type' => 'question', 'question' => 'Why?', 'answer' => '<p> </p>']]],
            ],
        ], new Catalog(), new SchemaContext('en'), ['title' => 'Existing page']);

        self::assertSame([
            'blocks[0].title: "Title" is required. Fill it in. If you cannot fill it in properly - for example there is no page to link to - ask the editor for it with a question, or choose another block.',
            'blocks[1].questions[0].answer: "Answer" is required. Fill it in. If you cannot fill it in properly - for example there is no page to link to - ask the editor for it with a question, or choose another block.',
        ], $result->errors);
    }

    public function testAKeptBlockKeepsItsFilledRequiredFields(): void
    {
        $base = ['title' => 'Page', 'blocks' => [['type' => 'hero', 'title' => 'Old hero', 'settings' => []]]];

        $kept = Fixtures::sanitizer()->sanitize('page', Fixtures::template(), [
            'blocks' => [['type' => 'hero', '_ref' => 0, 'background' => 'dark']],
        ], new Catalog(), new SchemaContext('en'), $base);

        self::assertSame([], $kept->errors);

        // a reference to a block of another type starts empty, so its required fields are needed
        $retyped = Fixtures::sanitizer()->sanitize('page', Fixtures::template(), [
            'blocks' => [['type' => 'faq', '_ref' => 0]],
        ], new Catalog(), new SchemaContext('en'), $base);

        self::assertSame(['blocks[0].questions: "Questions" is required. Fill it in. If you cannot fill it in properly - for example there is no page to link to - ask the editor for it with a question, or choose another block.'], $retyped->errors);
    }

    public function testThePageItselfOnlyNeedsWhatIsEmptyNow(): void
    {
        $sanitizer = Fixtures::sanitizer();

        self::assertSame([], $sanitizer->sanitize('page', Fixtures::template(), ['newsletter' => true], new Catalog(), new SchemaContext('en'), ['title' => 'Kept'])->errors);
        self::assertStringStartsWith('title: "Title" is required.', $sanitizer->sanitize('page', Fixtures::template(), ['newsletter' => true], new Catalog(), new SchemaContext('en'), [])->errors[0]);
        self::assertStringStartsWith('title: "Title" is required.', $sanitizer->sanitize('page', Fixtures::template(), ['title' => '  '], new Catalog(), new SchemaContext('en'), ['title' => 'Kept'])->errors[0], 'emptying it is not allowed');
    }

    public function testARequiredFieldTheAssistantCannotWriteIsForTheEditor(): void
    {
        $catalog = new Catalog([], [], ['hero' => new Guide(excludeFields: ['title'])]);

        $result = Fixtures::sanitizer()->sanitize('page', Fixtures::template(), [
            'blocks' => [['type' => 'hero', 'background' => 'dark']],
        ], $catalog, new SchemaContext('en'), ['title' => 'Page']);

        self::assertSame([], $result->errors, 'the model cannot fix it, so it is not sent back');
        self::assertSame(['blocks[0].title (Title) is required and has to be filled in by hand.'], $result->manual);
    }
}
