<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Content;

/**
 * Whether an editor works in a webspace. Only then does the AI context of that webspace - its
 * description, guidelines and known facts - go into the editor's prompts, and only then are the
 * facts the editor answers kept in it.
 */
interface WebspaceAccessInterface
{
    public function canUseWebspace(?int $userId, string $webspaceKey, string $locale): bool;
}
