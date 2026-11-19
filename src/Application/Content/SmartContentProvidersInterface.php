<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Content;

/**
 * The smart content providers of the project - pages, articles, snippets, media, contacts and
 * any of the project's own - described from their own configuration.
 */
interface SmartContentProvidersInterface
{
    /**
     * Null for a provider that does not exist: such a field is left alone.
     */
    public function describe(string $provider): ?SmartContentDescription;
}
