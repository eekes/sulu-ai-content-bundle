<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Catalog;

use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogAnalyzer;
use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogDescriber;
use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogFile;
use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Catalog\Guide;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TemplateInspectorInterface;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use PHPUnit\Framework\TestCase;

final class CatalogAnalyzerTest extends TestCase
{
    private string $path;

    /**
     * @var list<TypeDescription>
     */
    private array $templates = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/eekes-ai-content-'.bin2hex(random_bytes(4)).'/catalog.yaml';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
            rmdir(\dirname($this->path));
        }
    }

    public function testAChangedBlockWithEditedTextIsKeptAndReported(): void
    {
        $file = new CatalogFile($this->path);
        $this->templates = [self::page([new FieldDescription('text', 'text_editor', 'Text')])];
        $this->analyzer($file, 'First')->analyze(['page'], 'en');

        $edited = $this->textGuide($file);
        $file->save($file->load()->withBlock('text', new Guide('By hand', excludeFields: ['theme'], fingerprint: $edited->fingerprint, generated: $edited->generated)));

        // a field is added to the block
        $this->templates = [self::page([new FieldDescription('text', 'text_editor', 'Text'), new FieldDescription('title', 'text_line', 'Title')])];
        $report = $this->analyzer($file, 'Second')->analyze(['page'], 'en');

        self::assertContains(['kind' => 'block', 'key' => 'text', 'status' => CatalogAnalyzer::KEPT_EDITED, 'detail' => 'its fields changed since the text was written; check it, or run with --force'], $report);
        self::assertSame('By hand', $this->textGuide($file)->description);

        $this->analyzer($file, 'Third')->analyze(['page'], 'en', force: true);
        $guide = $this->textGuide($file);
        self::assertSame('Third', $guide->description);
        self::assertSame(['theme'], $guide->excludeFields);
        self::assertFalse($guide->isEdited());
    }

    public function testAChangedBlockThatNobodyEditedIsDescribedAgain(): void
    {
        $file = new CatalogFile($this->path);
        $this->templates = [self::page([new FieldDescription('text', 'text_editor', 'Text')])];
        $this->analyzer($file, 'First')->analyze(['page'], 'en');

        $this->templates = [self::page([new FieldDescription('body', 'text_editor', 'Text')])];
        $report = $this->analyzer($file, 'Second')->analyze(['page'], 'en');

        self::assertContains(['kind' => 'block', 'key' => 'text', 'status' => CatalogAnalyzer::REDESCRIBED, 'detail' => null], $report);
        self::assertSame('Second', $this->textGuide($file)->description);
    }

    private function textGuide(CatalogFile $file): Guide
    {
        $guide = $file->load()->block('text');
        self::assertNotNull($guide);

        return $guide;
    }

    /**
     * @param list<FieldDescription> $textFields
     */
    private static function page(array $textFields): TypeDescription
    {
        return new TypeDescription('default', 'Default', [
            new FieldDescription('blocks', 'block', 'Blocks', types: [new TypeDescription('text', 'Text', $textFields)]),
        ]);
    }

    private function analyzer(CatalogFile $file, string $description): CatalogAnalyzer
    {
        $inspector = new class($this) implements TemplateInspectorInterface {
            public function __construct(private readonly CatalogAnalyzerTest $test)
            {
            }

            public function getTemplates(string $formKey, string $locale, ?string $webspaceKey = null): array
            {
                return $this->test->templates();
            }

            public function getTemplate(string $formKey, string $templateKey, string $locale, ?string $webspaceKey = null): ?TypeDescription
            {
                return null;
            }
        };

        $describer = $this->createStub(CatalogDescriber::class);
        $describer->method('describe')->willReturn(new Guide($description, 'Always'));

        return new CatalogAnalyzer($inspector, $file, $describer);
    }

    /**
     * @return list<TypeDescription>
     */
    public function templates(): array
    {
        return $this->templates;
    }
}
