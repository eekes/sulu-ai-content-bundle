<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Application;

use Eekes\Sulu\AiContentBundle\EekesSuluAiContentBundle;
use Sulu\Bundle\TestBundle\Kernel\SuluTestKernel;
use Sulu\Snippet\Infrastructure\Symfony\HttpKernel\SuluSnippetBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bundle tests itself against this minimal application, never against the surrounding Sulu
 * skeleton: a suite that needs one cannot be run in CI or by an outside contributor.
 *
 * There is no real AI provider here. The platform is Symfony AI's mock platform, driven by the
 * ModelScript double, so a test decides what "the model" answers.
 */
class Kernel extends SuluTestKernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();

        // not part of Sulu's test kernel; the snippet field schema and search_snippets need it
        yield new SuluSnippetBundle();
        yield new EekesSuluAiContentBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(__DIR__.'/config/config.yaml');

        // symfony/var-exporter 8 no longer ships the LazyGhost proxies Doctrine ORM 3 falls back
        // on, and it is what a PHP 8.4 install resolves to, so proxies use native lazy objects there.
        if (\PHP_VERSION_ID >= 80400) {
            $loader->load(static function (ContainerBuilder $container): void {
                $container->loadFromExtension('doctrine', ['orm' => ['enable_native_lazy_objects' => true]]);
            });
        }
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return __DIR__.'/var/cache/'.$this->getContext().'/'.$this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return __DIR__.'/var/log';
    }
}
