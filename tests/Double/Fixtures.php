<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Double;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\MediaFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\SmartContentDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\SmartContentProvidersInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\TaxonomyFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\ContentSanitizer;
use Eekes\Sulu\AiContentBundle\Application\Schema\ContentSchemaBuilder;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\ChoiceFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\MediaFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\PageFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\ScalarFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\SmartContentFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\TextEditorFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\TextFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\HtmlCleaner;

/**
 * A template like the ones a project has, and the schema services wired without a container.
 */
final class Fixtures
{
    public const string PAGE_UUID = '3f1a0c2e-0000-4000-8000-000000000001';

    public static function template(): TypeDescription
    {
        $hero = new TypeDescription('hero', 'Hero', [
            new FieldDescription('title', 'text_line', 'Title', required: true),
            new FieldDescription('image', 'single_media_selection', 'Image'),
            new FieldDescription('button', 'link', 'Button'),
            new FieldDescription('background', 'single_select', 'Background', choices: [['value' => 'light', 'label' => 'Light'], ['value' => 'dark', 'label' => 'Dark']]),
        ], 'hero');

        $question = new TypeDescription('question', 'Question', [
            new FieldDescription('question', 'text_line', 'Question', required: true),
            new FieldDescription('answer', 'text_editor', 'Answer', required: true),
        ]);

        $faq = new TypeDescription('faq', 'FAQ', [
            new FieldDescription('title', 'text_line', 'Title'),
            new FieldDescription('questions', 'block', 'Questions', types: [$question], minOccurs: 1),
        ]);

        return new TypeDescription('default', 'Default', [
            new FieldDescription('title', 'text_line', 'Title', required: true, tags: ['sulu.rlp.part']),
            new FieldDescription('url', 'route', 'URL', required: true),
            new FieldDescription('blocks', 'block', 'Content', types: [$hero, $faq], maxOccurs: 3),
            new FieldDescription('related', 'page_selection', 'Related'),
            new FieldDescription('newsletter', 'checkbox', 'Newsletter'),
            // a provider the project does not have: left alone
            new FieldDescription('teaser', 'smart_content', 'Teaser', params: ['provider' => 'unknown']),
            new FieldDescription('news', 'smart_content', 'News', params: ['provider' => 'articles', 'present_as' => ['cards' => 'Cards', 'list' => 'List']]),
            new FieldDescription('children', 'smart_content', 'Child pages'),
        ]);
    }

    public static function schemaBuilder(): ContentSchemaBuilder
    {
        $pages = self::pageFinder();

        $media = new class implements MediaFinderInterface {
            public function search(string $query, string $locale, int $limit = 10): array
            {
                return [];
            }

            public function existing(array $ids): array
            {
                return array_values(array_intersect($ids, [7, 8]));
            }
        };

        return new ContentSchemaBuilder([
            new SmartContentFieldSchema(self::smartContentProviders(), self::taxonomyFinder(), $pages),
            new TextFieldSchema(),
            new ScalarFieldSchema(),
            new ChoiceFieldSchema(),
            new TextEditorFieldSchema(new HtmlCleaner()),
            new MediaFieldSchema($media),
            new PageFieldSchema($pages),
        ]);
    }

    /**
     * One page exists: PAGE_UUID.
     */
    public static function pageFinder(): PageFinderInterface
    {
        return new class implements PageFinderInterface {
            public function search(string $query, string $locale, ?string $webspaceKey, int $limit = 10): array
            {
                return [];
            }

            public function tree(string $locale, string $webspaceKey, int $limit = 150): array
            {
                return [];
            }

            public function url(string $uuid, string $locale): ?string
            {
                return $uuid === Fixtures::PAGE_UUID ? '/news' : null;
            }

            public function get(string $uuid, string $locale): ?array
            {
                return null;
            }

            public function existing(array $uuids): array
            {
                return array_values(array_intersect($uuids, [Fixtures::PAGE_UUID]));
            }
        };
    }

    /**
     * Pages with a page as data source; articles with types and without data source.
     */
    public static function smartContentProviders(): SmartContentProvidersInterface
    {
        return new class implements SmartContentProvidersInterface {
            public function describe(string $provider): ?SmartContentDescription
            {
                return match ($provider) {
                    'pages' => new SmartContentDescription('pages', 'pages', tags: true, categories: true, sorting: [['value' => 'published', 'label' => 'Published'], ['value' => 'title', 'label' => 'Title']], limit: true),
                    'articles' => new SmartContentDescription('articles', tags: true, categories: true, types: [['value' => 'blog', 'label' => 'Blog post'], ['value' => 'news', 'label' => 'News item']], sorting: [['value' => 'published', 'label' => 'Published']], limit: true),
                    default => null,
                };
            }
        };
    }

    /**
     * Categories 1 and 2, tag 5.
     */
    public static function taxonomyFinder(): TaxonomyFinderInterface
    {
        return new class implements TaxonomyFinderInterface {
            public function searchCategories(string $query, string $locale, int $limit = 30): array
            {
                return [];
            }

            public function searchTags(string $query, int $limit = 30): array
            {
                return [];
            }

            public function existingCategories(array $ids): array
            {
                return array_values(array_intersect($ids, [1, 2]));
            }

            public function existingTags(array $ids): array
            {
                return array_values(array_intersect($ids, [5]));
            }
        };
    }

    public static function sanitizer(): ContentSanitizer
    {
        return new ContentSanitizer(self::schemaBuilder());
    }
}
