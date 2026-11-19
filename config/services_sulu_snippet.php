<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AgentFactory;
use Eekes\Sulu\AiContentBundle\Application\Assistant\Tool\SearchSnippetsTool;
use Eekes\Sulu\AiContentBundle\Application\Content\SnippetFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\SnippetFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder\DoctrineSnippetFinder;

/*
 * Loaded only when Sulu's snippet bundle is registered: without it there are no snippet
 * selections to fill in and no snippets to search, and the model is not offered a tool that
 * cannot work.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->tag('sulu.context', ['context' => 'admin']);

    $services->set(DoctrineSnippetFinder::class);
    $services->alias(SnippetFinderInterface::class, DoctrineSnippetFinder::class);

    $services->set(SnippetFieldSchema::class)->tag(FieldSchemaInterface::TAG, ['priority' => -100]);
    $services->set(SearchSnippetsTool::class)->tag(AgentFactory::TOOL_TAG);
};
