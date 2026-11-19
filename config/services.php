<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AgentFactory;
use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantRunner;
use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Assistant\FactMemory;
use Eekes\Sulu\AiContentBundle\Application\Assistant\ReplyParser;
use Eekes\Sulu\AiContentBundle\Application\Assistant\Tool\GetPageTool;
use Eekes\Sulu\AiContentBundle\Application\Assistant\Tool\SearchCategoriesTool;
use Eekes\Sulu\AiContentBundle\Application\Assistant\Tool\SearchIconsTool;
use Eekes\Sulu\AiContentBundle\Application\Assistant\Tool\SearchMediaTool;
use Eekes\Sulu\AiContentBundle\Application\Assistant\Tool\SearchPagesTool;
use Eekes\Sulu\AiContentBundle\Application\Assistant\Tool\SearchTagsTool;
use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogAnalyzer;
use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogDescriber;
use Eekes\Sulu\AiContentBundle\Application\Catalog\CatalogFile;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TemplateInspectorInterface;
use Eekes\Sulu\AiContentBundle\Application\Command\AnalyzeCommand;
use Eekes\Sulu\AiContentBundle\Application\Content\IconFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\MediaFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\SmartContentProvidersInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\TaxonomyFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Message\RunAssistantHandler;
use Eekes\Sulu\AiContentBundle\Application\Model\ModelRegistry;
use Eekes\Sulu\AiContentBundle\Application\Prompt\SystemPromptBuilder;
use Eekes\Sulu\AiContentBundle\Application\Prompt\TurnPromptBuilder;
use Eekes\Sulu\AiContentBundle\Application\Schema\ContentSanitizer;
use Eekes\Sulu\AiContentBundle\Application\Schema\ContentSchemaBuilder;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\ChoiceFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\IconFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\MediaFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\PageFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\PageTreeRouteFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\ScalarFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\SmartContentFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\TextEditorFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\Field\TextFieldSchema;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\HtmlCleaner;
use Eekes\Sulu\AiContentBundle\Application\Schema\PlaceholderFinder;
use Eekes\Sulu\AiContentBundle\Application\Usage\BudgetGuard;
use Eekes\Sulu\AiContentBundle\Application\Usage\CostCalculator;
use Eekes\Sulu\AiContentBundle\Application\Usage\UsageRecorder;
use Eekes\Sulu\AiContentBundle\Domain\Repository\AiRunRepository;
use Eekes\Sulu\AiContentBundle\Domain\Repository\ConversationRepository;
use Eekes\Sulu\AiContentBundle\Domain\Repository\WebspaceContextRepository;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder\DoctrinePageFinder;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder\DoctrineTaxonomyFinder;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder\PageAccess;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder\SuluMediaFinder;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Icon\SuluIconFinder;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Metadata\SuluTemplateInspector;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\SmartContent\SuluSmartContentProviders;

/*
 * Every service a project may want to replace is registered under its class or interface, so
 * decorating or aliasing it is one line in the project.
 *
 * The assistant only exists in the admin: Sulu removes these services from the website kernel,
 * which has no form metadata to read templates from.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->tag('sulu.context', ['context' => 'admin']);

    $services->set(WebspaceContextRepository::class)->tag('doctrine.repository_service');
    $services->set(ConversationRepository::class)->tag('doctrine.repository_service');
    $services->set(AiRunRepository::class)->tag('doctrine.repository_service');

    $services->set(ModelRegistry::class)
        ->args([
            param('eekes_sulu_ai_content.models'),
            param('eekes_sulu_ai_content.default_model'),
            param('eekes_sulu_ai_content.catalog_model'),
        ]);

    $services->set(CostCalculator::class)
        ->args([param('eekes_sulu_ai_content.pricing.models'), param('eekes_sulu_ai_content.pricing.currency')]);

    $services->set(BudgetGuard::class)
        ->arg('$clock', service('clock'))
        ->arg('$monthlyLimit', param('eekes_sulu_ai_content.budget.monthly'))
        ->arg('$monthlyLimitPerWebspace', param('eekes_sulu_ai_content.budget.monthly_per_webspace'));

    $services->set(UsageRecorder::class)
        ->arg('$logPrompts', param('eekes_sulu_ai_content.log_prompts'))
        ->arg('$logger', service('logger')->nullOnInvalid())
        ->tag('monolog.logger', ['channel' => 'ai_content']);

    $services->set(CatalogFile::class)->args([param('eekes_sulu_ai_content.catalog_path')]);

    $services->set(SuluTemplateInspector::class)
        ->args([service('sulu_admin.metadata_provider_registry')]);
    $services->alias(TemplateInspectorInterface::class, SuluTemplateInspector::class);

    $services->set(PageAccess::class)
        ->arg('$accessControlManager', service('sulu_security.access_control_manager'))
        ->arg('$queryEnhancer', service('sulu_security.access_control_query_enhancer'))
        ->arg('$systemStore', service('sulu_security.system_store'))
        ->arg('$permissions', param('sulu_security.permissions'));
    $services->set(DoctrinePageFinder::class);
    $services->alias(PageFinderInterface::class, DoctrinePageFinder::class);

    $services->set(SuluMediaFinder::class)
        ->args([service('sulu_media.media_manager'), service('sulu.repository.media')]);
    $services->alias(MediaFinderInterface::class, SuluMediaFinder::class);

    $services->set(DoctrineTaxonomyFinder::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            param('sulu.model.category.class'),
            param('sulu.model.category_translation.class'),
            param('sulu.model.tag.class'),
        ]);
    $services->alias(TaxonomyFinderInterface::class, DoctrineTaxonomyFinder::class);

    $services->set(SuluSmartContentProviders::class)
        ->args([
            tagged_locator('sulu_content.smart_content_provider', indexAttribute: 'type', defaultIndexMethod: 'getType'),
            service('translator')->nullOnInvalid(),
        ]);
    $services->alias(SmartContentProvidersInterface::class, SuluSmartContentProviders::class);

    $services->set(SuluIconFinder::class)
        ->args([
            param('sulu_admin.icon_sets'),
            tagged_iterator('sulu_admin.icon_provider', indexAttribute: 'type'),
        ]);
    $services->alias(IconFinderInterface::class, SuluIconFinder::class);

    $services->set(HtmlCleaner::class);
    $services->set(TextFieldSchema::class)->tag(FieldSchemaInterface::TAG, ['priority' => -100]);
    $services->set(ScalarFieldSchema::class)->tag(FieldSchemaInterface::TAG, ['priority' => -100]);
    $services->set(ChoiceFieldSchema::class)->tag(FieldSchemaInterface::TAG, ['priority' => -100]);
    $services->set(TextEditorFieldSchema::class)->tag(FieldSchemaInterface::TAG, ['priority' => -100]);
    $services->set(MediaFieldSchema::class)->tag(FieldSchemaInterface::TAG, ['priority' => -100]);
    $services->set(PageFieldSchema::class)->tag(FieldSchemaInterface::TAG, ['priority' => -100]);
    $services->set(IconFieldSchema::class)->tag(FieldSchemaInterface::TAG, ['priority' => -100]);
    $services->set(PageTreeRouteFieldSchema::class)->tag(FieldSchemaInterface::TAG, ['priority' => -100]);
    $services->set(SmartContentFieldSchema::class)->tag(FieldSchemaInterface::TAG, ['priority' => -100]);

    // A project's own field schema (autoconfigured) gets the default priority 0 and so is asked
    // before the built-in ones: it can take over a built-in type as well as add a new one.
    $services->set(ContentSchemaBuilder::class)->args([tagged_iterator(FieldSchemaInterface::TAG)]);
    $services->set(ContentSanitizer::class);
    $services->set(SystemPromptBuilder::class)
        ->arg('$extraInstructions', param('eekes_sulu_ai_content.instructions'))
        ->arg('$maxPlanItems', param('eekes_sulu_ai_content.max_plan_items'));
    $services->set(TurnPromptBuilder::class);
    $services->set(ReplyParser::class);
    $services->set(AssistantScope::class);

    $services->set(SearchPagesTool::class)->tag(AgentFactory::TOOL_TAG);
    $services->set(GetPageTool::class)->tag(AgentFactory::TOOL_TAG);
    $services->set(SearchMediaTool::class)->tag(AgentFactory::TOOL_TAG);
    $services->set(SearchCategoriesTool::class)->tag(AgentFactory::TOOL_TAG);
    $services->set(SearchTagsTool::class)->tag(AgentFactory::TOOL_TAG);
    $services->set(SearchIconsTool::class)->tag(AgentFactory::TOOL_TAG);

    // The platform is set by the extension, from the "platform" option.
    $services->set(AgentFactory::class)
        ->args([
            abstract_arg('the Symfony AI platform'),
            tagged_iterator(AgentFactory::TOOL_TAG),
            param('eekes_sulu_ai_content.max_tool_calls'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'ai_content']);

    $services->set(AssistantRunner::class)
        ->arg('$siteStructureLimit', param('eekes_sulu_ai_content.site_structure_limit'))
        ->arg('$maxPlanItems', param('eekes_sulu_ai_content.max_plan_items'));
    $services->set(FactMemory::class);
    $services->set(PlaceholderFinder::class);
    $services->set(RunAssistantHandler::class)->tag('messenger.message_handler');

    $services->set(CatalogDescriber::class);
    $services->set(CatalogAnalyzer::class);
    $services->set(AnalyzeCommand::class)
        ->arg('$formKeys', param('eekes_sulu_ai_content.forms'))
        ->arg('$defaultLocale', param('eekes_sulu_ai_content.default_locale'))
        ->tag('console.command');
};
