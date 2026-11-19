<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Symfony\DependencyInjection;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const string ROOT_NODE = 'eekes_sulu_ai_content';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('platform')
                    ->info('The service id of the Symfony AI platform to use, e.g. "ai.platform.anthropic". Defaults to the platform Symfony AI registers as PlatformInterface.')
                    ->defaultValue(PlatformInterface::class)
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('models')
                    ->info('The models editors can choose from, by a key of your own. The first one is the default.')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('key')
                    ->arrayPrototype()
                        ->beforeNormalization()
                            ->ifString()
                            ->then(static fn (string $model) => ['model' => $model])
                        ->end()
                        ->children()
                            ->scalarNode('model')->isRequired()->cannotBeEmpty()->info('The model name, as the platform knows it')->end()
                            ->scalarNode('label')->defaultNull()->info('The name editors see')->end()
                            ->variableNode('options')->defaultValue([])->info('Passed on every call, e.g. {max_tokens: 8000}')->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('default_model')->defaultNull()->info('The key of the model a conversation starts with')->end()
                ->scalarNode('catalog_model')->defaultNull()->info('The key of the model the analyze command uses; the default model when empty')->end()
                ->scalarNode('catalog_path')
                    ->defaultValue('%kernel.project_dir%/config/ai_content/catalog.yaml')
                    ->info('Where the analyze command writes the description of your templates and blocks')
                ->end()
                ->arrayNode('forms')
                    ->info('The Sulu forms that get the assistant button')
                    ->scalarPrototype()->end()
                    ->defaultValue(['page', 'article', 'snippet'])
                ->end()
                ->integerNode('site_structure_limit')
                    ->defaultValue(150)
                    ->min(0)
                    ->info('How many pages of the webspace go into every prompt as the site structure (title, template, URL), so the assistant knows the site without asking. 0 switches it off.')
                ->end()
                ->integerNode('max_tool_calls')->defaultValue(12)->min(0)->info('Tool rounds per turn before the run is stopped')->end()
                ->integerNode('max_plan_items')->defaultValue(12)->min(1)->info('The most pages or articles one plan of the planner may hold; every item is written by its own model call')->end()
                ->booleanNode('log_prompts')
                    ->defaultTrue()
                    ->info('Keep the full prompt and answer of every run in the usage log. Switch off when page content must not be stored twice.')
                ->end()
                ->scalarNode('instructions')->defaultNull()->info('Extra instructions added to the system prompt of every run, in every webspace')->end()
                ->booleanNode('publish_new_snippets')
                    ->defaultTrue()
                    ->info('Publish the snippets the assistant creates when the editor applies a reply. They only show where a published page selects them. Set to false to create them as drafts, which the website shows only once someone publishes them.')
                ->end()
                ->scalarNode('default_locale')->defaultValue('en')->info('The locale the analyze command reads field labels in')->end()
                ->arrayNode('pricing')
                    ->addDefaultsIfNotSet()
                    ->info('Prices per million tokens, to estimate what runs cost. A model without a price is logged without a cost.')
                    ->children()
                        ->scalarNode('currency')->defaultValue('USD')->end()
                        ->arrayNode('models')
                            // model names contain dashes, which Symfony would turn into underscores
                            ->normalizeKeys(false)
                            ->useAttributeAsKey('model')
                            ->arrayPrototype()
                                ->children()
                                    ->floatNode('input')->isRequired()->end()
                                    ->floatNode('output')->isRequired()->end()
                                    ->floatNode('cache_read')->defaultNull()->end()
                                    ->floatNode('cache_write')->defaultNull()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('budget')
                    ->addDefaultsIfNotSet()
                    ->info('Stop new runs once the estimated cost of this month reaches a limit')
                    ->children()
                        ->floatNode('monthly')->defaultNull()->end()
                        ->floatNode('monthly_per_webspace')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
