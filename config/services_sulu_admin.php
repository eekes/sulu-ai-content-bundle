<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Admin\AiContentAdmin;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Admin\RunPresenter;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Controller\AssistantController;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Controller\UsageController;
use Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Controller\WebspaceContextController;
use Eekes\Sulu\AiContentBundle\Infrastructure\Symfony\EventListener\DeferredRunDispatcher;

/*
 * Loaded only when the Sulu admin bundle is registered, and like every service of the bundle
 * only kept in the admin kernel.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->tag('sulu.context', ['context' => 'admin']);

    $services->set(AiContentAdmin::class)
        ->arg('$viewBuilderFactory', service('sulu_admin.view_builder_factory'))
        ->arg('$securityChecker', service('sulu_security.security_checker'))
        ->arg('$webspaceManager', service('sulu_core.webspace.webspace_manager'))
        ->arg('$formKeys', param('eekes_sulu_ai_content.forms'))
        ->arg('$publishNewSnippets', param('eekes_sulu_ai_content.publish_new_snippets'))
        ->tag('sulu.admin');

    $services->set(RunPresenter::class)
        ->arg('$userRepository', service('sulu.repository.user'))
        ->arg('$translator', service('translator'));

    // The controllers are tagged by hand and never autoconfigured. Symfony answers a #[Route]
    // attribute on an autoconfigured service with the routing.controller tag as well, and an
    // application that imports "resource: routing.controllers" - the default of the Symfony
    // skeleton - then publishes these routes a second time, without the /admin/api prefix and
    // outside the firewall that guards the administration interface.
    //
    // Without autoconfiguration the container.service_subscriber tag has to be set by hand, or
    // AbstractController is never handed its container and every request fails.
    $controller = static fn (string $class) => $services->set($class)
        ->arg('$securityChecker', service('sulu_security.security_checker'))
        ->tag('container.service_subscriber')
        ->tag('controller.service_arguments');

    $services->set(DeferredRunDispatcher::class)
        ->arg('$messageBus', service('messenger.default_bus'))
        ->arg('$logger', service('logger')->nullOnInvalid())
        ->tag('kernel.event_subscriber')
        ->tag('monolog.logger', ['channel' => 'ai_content']);

    $controller(AssistantController::class)
        ->arg('$clock', service('clock'))
        ->arg('$webspaceManager', service('sulu_core.webspace.webspace_manager'));
    $controller(WebspaceContextController::class)
        ->arg('$webspaceManager', service('sulu_core.webspace.webspace_manager'));
    $controller(UsageController::class)
        ->arg('$restHelper', service('sulu_core.doctrine_rest_helper'))
        ->arg('$fieldDescriptorFactory', service('sulu_core.list_builder.field_descriptor_factory'))
        ->arg('$listBuilderFactory', service('sulu_core.doctrine_list_builder_factory'));
};
