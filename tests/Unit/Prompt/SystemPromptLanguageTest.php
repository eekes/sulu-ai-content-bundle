<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Prompt;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use Eekes\Sulu\AiContentBundle\Application\Prompt\LanguageName;
use Eekes\Sulu\AiContentBundle\Application\Prompt\SystemPromptBuilder;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;
use Eekes\Sulu\AiContentBundle\Tests\Double\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * A website's context, facts and pages are often in another language than the editor writes in
 * (a Dutch site with an English locale, an agency writing for a client): the model has to talk
 * in the editor's language and write in the locale's, whatever the rest of the prompt is in.
 */
final class SystemPromptLanguageTest extends TestCase
{
    private const string RULE = 'Talk to the editor in the language of the editor\'s latest message - your message, questions and their options, the outline and suggestions - even when the website, its pages, the known facts or the guidelines are in another language.';

    public function testTheAssistantTalksInTheEditorsLanguageAndWritesInTheLocales(): void
    {
        $template = Fixtures::template();
        $prompt = (new SystemPromptBuilder(Fixtures::schemaBuilder()))->build('page', $template, [$template], new Catalog(), null, new SchemaContext('en', 'website'));

        self::assertStringContainsString(self::RULE, $prompt);
        self::assertStringContainsString('Write the content in English (locale "en"), also when the editor, the facts or the other pages use another language: translate what you take from them.', $prompt);
    }

    public function testThePlannerTalksInTheEditorsLanguage(): void
    {
        $prompt = (new SystemPromptBuilder(Fixtures::schemaBuilder()))->buildPlanner('page', [new TypeDescription('default', 'Default', [])], new Catalog(), null, 'en');

        self::assertStringContainsString(self::RULE, $prompt);
    }

    public function testALanguageWithoutANameKeepsItsCode(): void
    {
        self::assertSame('English (locale "en")', LanguageName::of('en'));
        self::assertSame('Dutch (locale "nl_BE")', LanguageName::of('nl_BE'));
        self::assertSame('the language of the locale "xx-made-up"', LanguageName::of('xx-made-up'));
    }
}
