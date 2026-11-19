<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Schema;

use Eekes\Sulu\AiContentBundle\Application\Schema\HtmlCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlCleanerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'allowed markup stays' => ['<h2>Title</h2><p>Some <strong>bold</strong> and <em>italic</em></p><ul><li>One</li></ul>', '<h2>Title</h2><p>Some <strong>bold</strong> and <em>italic</em></p><ul><li>One</li></ul>'];
        yield 'scripts and styles go with their content' => ['<p>Hi</p><script>alert(1)</script><style>p{}</style>', '<p>Hi</p>'];
        yield 'unknown elements are unwrapped' => ['<div class="x"><p>Kept <span style="color:red">text</span></p></div>', '<p>Kept text</p>'];
        yield 'attributes are dropped' => ['<p class="lead" onclick="x()">Hi</p>', '<p>Hi</p>'];
        yield 'safe links stay' => ['<a href="https://example.com" target="_blank">x</a>', '<a href="https://example.com" target="_blank">x</a>'];
        yield 'javascript links lose their target' => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'];
        yield 'sulu links stay' => ['<sulu-link href="abc-123" provider="page">About</sulu-link>', '<sulu-link href="abc-123" provider="page">About</sulu-link>'];
        // Sulu's "external" provider prints the href as it is: a javascript: URL would reach the website
        yield 'sulu links to anything but a page are unwrapped' => ['<p><sulu-link href="javascript:alert(1)" provider="external">Click</sulu-link> <sulu-link href="12" provider="media">File</sulu-link></p>', '<p>Click File</p>'];
        yield 'a sulu link without provider is a page link' => ['<sulu-link href="abc-123">About</sulu-link>', '<sulu-link href="abc-123">About</sulu-link>'];
        yield 'unicode survives' => ['<p>Brood & banket – café</p>', '<p>Brood &amp; banket – café</p>'];
        yield 'empty stays empty' => ['  ', ''];
    }

    #[DataProvider('cases')]
    public function testClean(string $html, string $expected): void
    {
        self::assertSame($expected, (new HtmlCleaner())->clean($html));
    }
}
