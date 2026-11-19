<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Assistant;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantReply;
use Eekes\Sulu\AiContentBundle\Application\Assistant\ReplyParser;
use PHPUnit\Framework\TestCase;

final class ReplyParserTest extends TestCase
{
    public function testJsonInACodeFenceWithTextAround(): void
    {
        $reply = (new ReplyParser())->parse("Sure!\n```json\n{\"type\": \"outline\", \"message\": \"Here\", \"outline\": {\"template\": \"default\", \"blocks\": [{\"type\": \"hero\", \"purpose\": \"Welcome\"}, {\"nope\": 1}]}}\n```\nAnything else?");

        self::assertSame(AssistantReply::TYPE_OUTLINE, $reply->type);
        self::assertSame('Here', $reply->message);
        self::assertSame(['template' => 'default', 'templateTitle' => 'default', 'blocks' => [['type' => 'hero', 'title' => 'hero', 'purpose' => 'Welcome', 'snippets' => []]]], $reply->outline);
    }

    public function testPlainTextBecomesAMessage(): void
    {
        $reply = (new ReplyParser())->parse('  I would make it shorter.  ');

        self::assertSame(AssistantReply::TYPE_MESSAGE, $reply->type);
        self::assertSame('I would make it shorter.', $reply->message);
    }

    public function testBrokenJsonBecomesAMessage(): void
    {
        self::assertSame(AssistantReply::TYPE_MESSAGE, (new ReplyParser())->parse('{"type": "content", "message": ')->type);
    }

    public function testAnUnknownTypeIsAMessage(): void
    {
        self::assertSame(AssistantReply::TYPE_MESSAGE, (new ReplyParser())->parse('{"type": "poem", "message": "Roses"}')->type);
    }

    public function testQuestionsAsStringsOrObjects(): void
    {
        $reply = (new ReplyParser())->parse('{"type": "questions", "message": "", "questions": ["Who?", {"question": "When?", "options": ["Now", 3, ""]}, {"question": "Which?", "options": ["A", "B"], "multiple": true, "remember": true}, {"question": "Sure?", "multiple": "yes"}], "suggestions": ["Shorter", null]}');

        self::assertSame([
            ['question' => 'Who?', 'options' => [], 'multiple' => false, 'remember' => false],
            ['question' => 'When?', 'options' => ['Now'], 'multiple' => false, 'remember' => false],
            ['question' => 'Which?', 'options' => ['A', 'B'], 'multiple' => true, 'remember' => true],
            ['question' => 'Sure?', 'options' => [], 'multiple' => false, 'remember' => false],
        ], $reply->questions);
        self::assertSame(['Shorter'], $reply->suggestions);
    }

    public function testContentWithSeoAndExcerpt(): void
    {
        $reply = (new ReplyParser())->parse('{"type": "content", "message": "Done", "content": {"template": "landing", "properties": {"title": "X"}, "seo": {"title": "<b>SEO</b>", "description": "", "robots": "x"}, "excerpt": {"title": "Teaser"}}}');

        self::assertSame('landing', $reply->template);
        self::assertSame(['title' => 'X'], $reply->properties);
        self::assertSame(['title' => 'SEO'], $reply->seo);
        self::assertSame(['title' => 'Teaser'], $reply->excerpt);
    }

    public function testAPlanItemCanBeTheNewPageTheOthersGoBelow(): void
    {
        $reply = (new ReplyParser())->parse('{"type": "plan", "message": "Two.", "plan": {"items": [{"kind": "page", "key": " blog ", "title": "Blog", "template": "overview", "parent": "home", "brief": "The blog."}, {"kind": "poem", "key": 3, "title": "Bread", "template": "article", "parent": "new:blog"}]}}');

        self::assertSame([
            ['title' => 'Blog', 'template' => 'overview', 'templateTitle' => 'overview', 'parent' => 'home', 'parentTitle' => null, 'brief' => 'The blog.', 'kind' => 'page', 'key' => 'blog'],
            ['title' => 'Bread', 'template' => 'article', 'templateTitle' => 'article', 'parent' => 'new:blog', 'parentTitle' => null, 'brief' => '', 'kind' => null, 'key' => null],
        ], $reply->plan);
    }
}
