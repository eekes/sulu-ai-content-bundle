<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant;

use Eekes\Sulu\AiContentBundle\Application\Model\ModelChoice;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Builds a Symfony AI agent for one model, on the platform the project configured, with the
 * assistant's tools. A project adds a tool of its own by tagging any #[AsTool] service with
 * "eekes_sulu_ai_content.tool".
 *
 * A failing tool does not end the run: the error goes back to the model as the tool's answer.
 */
class AgentFactory
{
    public const string TOOL_TAG = 'eekes_sulu_ai_content.tool';

    /**
     * @param iterable<object> $tools
     */
    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly iterable $tools,
        private readonly int $maxToolCalls = 12,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function create(ModelChoice $model, bool $withTools = true): AgentInterface
    {
        if ($model->model === '') {
            throw new \LogicException(\sprintf('The model "%s" has no model name.', $model->key));
        }

        $toolbox = $withTools
            ? new FaultTolerantToolbox(new Toolbox(iterator_to_array($this->tools, false), logger: $this->logger))
            : null;

        return new Agent($this->platform, $model->model, toolbox: $toolbox, maxToolCalls: $this->maxToolCalls, name: 'eekes_ai_content');
    }
}
