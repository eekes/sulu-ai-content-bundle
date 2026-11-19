<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant;

/**
 * The locale, webspace and editor of the run in progress, for the tools and finders: a model only
 * passes the arguments a tool declares, so where to search - and as whom - comes from here. A run
 * in a worker has no logged-in user, so the editor is the one who sent the turn.
 */
final class AssistantScope
{
    private string $locale = 'en';

    private ?string $webspaceKey = null;

    private ?int $userId = null;

    public function enter(string $locale, ?string $webspaceKey, ?int $userId = null): void
    {
        $this->locale = $locale;
        $this->webspaceKey = $webspaceKey;
        $this->userId = $userId;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getWebspaceKey(): ?string
    {
        return $this->webspaceKey;
    }

    /**
     * The editor the assistant reads content for, null outside a run.
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }
}
