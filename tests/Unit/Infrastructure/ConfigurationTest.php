<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Infrastructure;

use Eekes\Sulu\AiContentBundle\Infrastructure\Symfony\DependencyInjection\Configuration;
use Eekes\Sulu\AiContentBundle\Tests\Double\Json;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testModelNamesWithDashesKeepTheirDashes(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'models' => ['smart-one' => 'claude-sonnet-4-5'],
            'pricing' => ['models' => ['claude-sonnet-4-5' => ['input' => 3, 'output' => 15]]],
        ]]);

        self::assertSame(['smart-one' => ['model' => 'claude-sonnet-4-5', 'label' => null, 'options' => []]], $config['models']);
        self::assertArrayHasKey('claude-sonnet-4-5', Json::array($config, 'pricing.models'));
        self::assertSame(['page', 'article', 'snippet'], $config['forms']);
        self::assertTrue($config['log_prompts']);
        self::assertTrue($config['publish_new_snippets'], 'a snippet a page selects should show on the website');
        self::assertSame(12, $config['max_plan_items']);
    }
}
