<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Message;

/**
 * Runs one assistant turn. Route it to an async transport and the editor's request returns at
 * once while a worker talks to the model; without routing it is handled in the request itself.
 * The panel polls the run either way.
 */
final readonly class RunAssistant
{
    /**
     * @param array<string, mixed> $request see AssistantRequest::toArray()
     */
    public function __construct(
        public string $runId,
        public array $request,
    ) {
    }
}
