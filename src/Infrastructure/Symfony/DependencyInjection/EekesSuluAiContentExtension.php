<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Symfony\DependencyInjection;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AgentFactory;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;
use Eekes\Sulu\AiContentBundle\Domain\Entity\WebspaceContext;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Controller\AssistantController;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Controller\UsageController;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Controller\WebspaceContextController;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class EekesSuluAiContentExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{platform: string, models: array<string, array{model: string, label: null|string, options: array<string, mixed>}>, default_model: null|string, catalog_model: null|string, catalog_path: string, forms: list<string>, max_tool_calls: int, max_plan_items: int, site_structure_limit: int, log_prompts: bool, instructions: null|string, default_locale: string, publish_new_snippets: bool, pricing: array{currency: string, models: array<string, array{input: float, output: float, cache_read: null|float, cache_write: null|float}>}, budget: array{monthly: null|float, monthly_per_webspace: null|float}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);
        $root = Configuration::ROOT_NODE;

        $container->setParameter($root.'.platform', $config['platform']);
        $container->setParameter($root.'.models', $config['models']);
        $container->setParameter($root.'.default_model', $config['default_model']);
        $container->setParameter($root.'.catalog_model', $config['catalog_model']);
        $container->setParameter($root.'.catalog_path', $config['catalog_path']);
        $container->setParameter($root.'.forms', $config['forms']);
        $container->setParameter($root.'.max_tool_calls', $config['max_tool_calls']);
        $container->setParameter($root.'.max_plan_items', $config['max_plan_items']);
        $container->setParameter($root.'.site_structure_limit', $config['site_structure_limit']);
        $container->setParameter($root.'.log_prompts', $config['log_prompts']);
        $container->setParameter($root.'.instructions', $config['instructions']);
        $container->setParameter($root.'.default_locale', $config['default_locale']);
        $container->setParameter($root.'.publish_new_snippets', $config['publish_new_snippets']);
        $container->setParameter($root.'.pricing.currency', $config['pricing']['currency']);
        $container->setParameter($root.'.pricing.models', $config['pricing']['models']);
        $container->setParameter($root.'.budget.monthly', $config['budget']['monthly']);
        $container->setParameter($root.'.budget.monthly_per_webspace', $config['budget']['monthly_per_webspace']);

        $container->registerForAutoconfiguration(FieldSchemaInterface::class)->addTag(FieldSchemaInterface::TAG);

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 4).'/config'));
        $loader->load('services.php');
        $container->getDefinition(AgentFactory::class)->setArgument(0, new Reference($config['platform']));

        if (self::hasBundle($container, 'SuluSnippetBundle')) {
            $loader->load('services_sulu_snippet.php');
        }

        if (self::hasBundle($container, 'SuluAdminBundle')) {
            $loader->load('services_sulu_admin.php');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'EekesSuluAiContent' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => \dirname(__DIR__, 4).'/src/Domain/Entity',
                            'prefix' => 'Eekes\Sulu\AiContentBundle\Domain\Entity',
                            'alias' => 'EekesSuluAiContent',
                        ],
                    ],
                ],
            ]);
        }

        // Declaring the channel is what makes the monolog.logger tag in services.php resolve.
        if ($container->hasExtension('monolog')) {
            $container->prependExtensionConfig('monolog', ['channels' => ['ai_content']]);
        }

        if ($container->hasExtension('sulu_admin')) {
            $container->prependExtensionConfig('sulu_admin', [
                'lists' => ['directories' => [\dirname(__DIR__, 4).'/config/lists']],
                'forms' => ['directories' => [\dirname(__DIR__, 4).'/config/forms']],
                'resources' => [
                    AiRun::RESOURCE_KEY => [
                        'routes' => ['list' => UsageController::LIST_ROUTE, 'detail' => UsageController::DETAIL_ROUTE],
                    ],
                    WebspaceContext::RESOURCE_KEY => [
                        'routes' => ['list' => WebspaceContextController::ROUTE, 'detail' => WebspaceContextController::ROUTE],
                    ],
                    AssistantController::CONVERSATION_RESOURCE_KEY => [
                        'routes' => ['list' => AssistantController::POST_CONVERSATION_ROUTE, 'detail' => AssistantController::GET_CONVERSATION_ROUTE],
                    ],
                    AssistantController::HISTORY_RESOURCE_KEY => [
                        'routes' => ['list' => AssistantController::GET_CONVERSATIONS_ROUTE, 'detail' => AssistantController::GET_CONVERSATIONS_ROUTE],
                    ],
                    AssistantController::PLAN_OPTIONS_RESOURCE_KEY => [
                        'routes' => ['list' => AssistantController::GET_PLAN_OPTIONS_ROUTE, 'detail' => AssistantController::GET_PLAN_OPTIONS_ROUTE],
                    ],
                    AssistantController::TURN_RESOURCE_KEY => [
                        'routes' => ['list' => AssistantController::POST_TURN_ROUTE, 'detail' => AssistantController::GET_TURN_ROUTE],
                    ],
                ],
            ]);
        }
    }

    public function getAlias(): string
    {
        return Configuration::ROOT_NODE;
    }

    /**
     * Whether a bundle is registered. hasExtension() cannot answer that inside load(): the
     * container load() gets holds no extensions.
     */
    private static function hasBundle(ContainerBuilder $container, string $name): bool
    {
        $bundles = $container->getParameter('kernel.bundles');

        return \is_array($bundles) && \array_key_exists($name, $bundles);
    }
}
