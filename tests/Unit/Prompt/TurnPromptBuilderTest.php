<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Prompt;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Prompt\TurnPromptBuilder;
use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;
use Eekes\Sulu\AiContentBundle\Tests\Double\Fixtures;
use PHPUnit\Framework\TestCase;

final class TurnPromptBuilderTest extends TestCase
{
    public function testTheFormIsSentWithNumberedBlocksAndWithoutWhatTheAssistantCannotWrite(): void
    {
        $prompt = (new TurnPromptBuilder(Fixtures::schemaBuilder()))->build(
            AiAction::RewriteBlock,
            'Shorter',
            'page',
            Fixtures::template(),
            [
                'title' => 'Bread',
                'url' => '/bread',
                'teaser' => ['dataSource' => 'x'],
                'blocks' => [
                    ['type' => 'hero', 'title' => 'Hi', 'settings' => ['hidden' => true]],
                    ['type' => 'faq', 'questions' => [['type' => 'question', 'question' => 'Q', 'answer' => 'A']]],
                ],
                'seo' => ['title' => 'Bread SEO', 'description' => ''],
            ],
            new Catalog(),
            focusBlock: 1,
            focusProperty: 'blocks',
        );

        self::assertStringContainsString('rework only the block with "_ref" 1 in "blocks"', $prompt);
        self::assertStringContainsString('"_ref": 1', $prompt);
        self::assertStringContainsString('"question": "Q"', $prompt);
        self::assertStringContainsString('"title": "Bread SEO"', $prompt);
        self::assertStringContainsString('Request of the editor: Shorter', $prompt);
        self::assertStringNotContainsString('/bread', $prompt);
        self::assertStringNotContainsString('dataSource', $prompt);
        self::assertStringNotContainsString('hidden', $prompt);
    }

    public function testATranslationSendsTheSourceLocale(): void
    {
        $prompt = (new TurnPromptBuilder(Fixtures::schemaBuilder()))->build(
            AiAction::Translate,
            '',
            'page',
            Fixtures::template(),
            [],
            new Catalog(),
            sourceContent: ['title' => 'Brood'],
            sourceLocale: 'nl',
        );

        self::assertStringContainsString('translate the page from the locale "nl"', $prompt);
        self::assertStringContainsString('"title": "Brood"', $prompt);
        self::assertStringContainsString('(none - do what the action says)', $prompt);
    }

    public function testEveryTurnSaysWhichLanguageTheContentIsWrittenIn(): void
    {
        $prompt = (new TurnPromptBuilder(Fixtures::schemaBuilder()))->build(AiAction::Chat, 'Write the page', 'page', Fixtures::template(), [], new Catalog(), locale: 'en');

        self::assertStringContainsString("Language of the content: English (locale \"en\"). Write every text in it, whatever language the editor, the facts or the other pages use.\n\nRequest of the editor: Write the page", $prompt);
    }
}
