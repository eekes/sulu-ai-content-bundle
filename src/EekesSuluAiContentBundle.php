<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle;

use Eekes\Sulu\AiContentBundle\Infrastructure\Symfony\DependencyInjection\EekesSuluAiContentExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The bundle follows the directory layout Symfony recommends for new bundles: src/ holds nothing
 * but PHP, and config/, translations/ and assets/ sit next to it at the root.
 */
class EekesSuluAiContentBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new EekesSuluAiContentExtension();
    }
}
