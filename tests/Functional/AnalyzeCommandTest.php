<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Functional;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogFile;
use Eekes\Sulu\AiContentBundle\Application\Catalog\Guide;
use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;
use Eekes\Sulu\AiContentBundle\Tests\Double\Json;
use Eekes\Sulu\AiContentBundle\Tests\Double\ModelScript;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

/**
 * The analyze command against the templates of the test application: two page templates, a global
 * hero block and inline text, faq and question blocks.
 */
final class AnalyzeCommandTest extends FunctionalTestCase
{
    public function testEveryTemplateAndBlockIsDescribedAndLogged(): void
    {
        $this->scriptDescriptions(7);

        $tester = $this->analyze(['--form' => ['page']]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $catalog = $this->catalog();
        self::assertSame(['default', 'landing'], array_keys($catalog->templates['page']));
        self::assertSame(['faq', 'faq_snippets', 'hero', 'question', 'text'], array_keys($catalog->blocks));
        self::assertSame('Described 2', $catalog->block('faq')?->description);
        self::assertNotEmpty($catalog->block('hero')?->fingerprint);
        self::assertFalse($catalog->block('hero')->isEdited());

        $runs = self::runs();
        self::assertCount(7, $runs);
        self::assertSame(AiAction::AnalyzeBlocks, $runs[0]->getAction());
        self::assertSame('Describe the template "landing"', $runs[0]->prompt);
        self::assertStringContainsString('"key": "hero"', self::script()->prompt(4));
        self::assertStringContainsString('"shows_snippets_of_template": [', self::script()->prompt(3));
        self::assertStringContainsString('"lists_automatically": "pages"', self::script()->prompt(0));
    }

    public function testASecondRunOnlyDescribesWhatIsNewAndKeepsWhatAPersonWrote(): void
    {
        $this->scriptDescriptions(7);
        $this->analyze(['--form' => ['page']]);

        $text = $this->catalog()->block('text');
        self::assertNotNull($text);
        $this->write($this->catalog()
            ->withBlock('text', new Guide('Written by a person.', excludeFields: ['theme'], fingerprint: $text->fingerprint, generated: $text->generated))
            ->withoutBlock('faq')
            ->withBlock('gone', new Guide('Old')));

        self::script()->reset();
        $this->scriptDescriptions(1);
        $tester = $this->analyze(['--form' => ['page']]);

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/faq\s+described/', $display);
        self::assertMatchesRegularExpression('/hero\s+unchanged/', $display);
        // the fields of "text" did not change, so its edited text simply stays
        self::assertMatchesRegularExpression('/text\s+unchanged/', $display);
        self::assertMatchesRegularExpression('/gone\s+stale/', $display);
        self::assertCount(1, self::script()->calls);

        $catalog = $this->catalog();
        self::assertSame('Written by a person.', $catalog->block('text')?->description);
        self::assertSame(['theme'], $catalog->block('text')->excludeFields);
        self::assertNotNull($catalog->block('gone'));
    }

    public function testForceDescribesEverythingAgainButKeepsExcludedFields(): void
    {
        $this->scriptDescriptions(7);
        $this->analyze(['--form' => ['page']]);

        $this->write($this->catalog()->withBlock('text', new Guide('Written by a person.', excludeFields: ['theme'])));

        self::script()->reset();
        $this->scriptDescriptions(7);
        $this->analyze(['--form' => ['page'], '--force' => true, '--prune' => true]);

        $catalog = $this->catalog();
        self::assertStringStartsWith('Described', (string) $catalog->block('text')?->description);
        self::assertSame(['theme'], $catalog->block('text')?->excludeFields);
    }

    public function testWithoutAiTheCatalogGetsEmptyEntriesToFillIn(): void
    {
        $tester = $this->analyze(['--form' => ['page'], '--no-ai' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], self::script()->calls);
        $hero = Yaml::parseFile($this->path());
        self::assertSame('', Json::at($hero, 'blocks.hero.description'), 'the file shows what can be filled in');
        self::assertSame([], Json::at($hero, 'blocks.hero.exclude_fields'));
        self::assertCount(0, self::runs());
    }

    public function testADryRunWritesNothing(): void
    {
        $this->analyze(['--form' => ['page'], '--dry-run' => true]);

        self::assertFileDoesNotExist($this->path());
        self::assertSame([], self::script()->calls);
    }

    public function testAFailedDescriptionIsReportedAndTheRestGoesOn(): void
    {
        self::script()->reply(ModelScript::text('no json at all'));
        $this->scriptDescriptions(6);

        $tester = $this->analyze(['--form' => ['page']]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/page\/landing\s+failed/', $tester->getDisplay());
        self::assertNotNull($this->catalog()->block('hero'));
    }

    private function scriptDescriptions(int $count): void
    {
        for ($index = 0; $index < $count; ++$index) {
            self::script()->reply(ModelScript::json([
                'description' => 'Described '.$index,
                'use_when' => 'Always',
                'avoid_when' => 'Never',
                'tips' => 'Be brief',
            ], 300, 80));
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function analyze(array $options): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('eekes:ai-content:analyze'));
        $tester->execute($options);

        return $tester;
    }

    private function path(): string
    {
        return self::service(CatalogFile::class)->getPath();
    }

    private function catalog(): Catalog
    {
        return Catalog::fromArray(Json::array(Yaml::parseFile($this->path())));
    }

    /**
     * What a person editing the file does: a later modification time makes a running process
     * read it again.
     */
    private function write(Catalog $catalog): void
    {
        file_put_contents($this->path(), Yaml::dump($catalog->toArray(), 5));
        touch($this->path(), time() + 5);
    }
}
