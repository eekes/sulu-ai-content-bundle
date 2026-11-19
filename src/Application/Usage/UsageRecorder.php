<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Usage;

use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;
use Eekes\Sulu\AiContentBundle\Domain\Repository\AiRunRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\ResultInterface;

/**
 * Keeps the run in the database up to date while it happens - so the panel can show progress and
 * a run that crashes halfway still shows up - and writes one log line per finished run to the
 * "ai_content" channel, with the tokens and the estimated cost as context.
 */
class UsageRecorder
{
    public function __construct(
        private readonly AiRunRepository $runRepository,
        private readonly CostCalculator $costCalculator,
        private readonly bool $logPrompts,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function start(AiRun $run, string $progress): void
    {
        $run->start();
        $run->progress = $progress;
        $this->runRepository->save($run);
    }

    public function progress(AiRun $run, string $progress): void
    {
        $run->progress = $progress;
        $this->runRepository->save($run);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function toolCall(AiRun $run, string $name, array $arguments): void
    {
        $run->recordToolCall($name, $arguments);
        // a code, shown to the editor in the language of the admin (RunPresenter)
        $run->progress = 'tool:'.$name;
        $this->runRepository->save($run);
    }

    public function addUsage(AiRun $run, ResultInterface $result): void
    {
        ['tokens' => $tokens, 'requests' => $requests] = TokenUsageReader::fromResult($result);
        $run->addUsage($tokens, $requests);
        $run->setCostMicros($this->costCalculator->costMicros($run->model, $run->getTokens()));
        $this->runRepository->save($run);
    }

    /**
     * @param list<array{role: string, content: string}> $transcript
     */
    public function transcript(AiRun $run, array $transcript): void
    {
        if ($this->logPrompts) {
            $run->transcript = $transcript;
        }
    }

    /**
     * @param array<string, mixed> $reply
     */
    public function succeed(AiRun $run, array $reply): void
    {
        $run->succeed($reply);
        $this->runRepository->save($run);
        $this->logger->info('AI run {action} finished: {tokens} tokens with {model}.', $this->context($run));
    }

    public function fail(AiRun $run, \Throwable $exception): void
    {
        $run->fail($exception->getMessage());
        $this->runRepository->save($run);
        $this->logger->error('AI run {action} failed: {error}', $this->context($run) + ['exception' => $exception]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(AiRun $run): array
    {
        return [
            'run' => $run->id,
            'action' => $run->getAction()->value,
            'status' => $run->getStatus()->value,
            'model' => $run->model,
            'modelKey' => $run->modelKey,
            'webspace' => $run->webspaceKey,
            'locale' => $run->locale,
            'form' => $run->formKey,
            'resource' => $run->resourceId,
            'user' => $run->userId,
            'requests' => $run->requests,
            'tokens' => $run->totalTokens,
            'inputTokens' => $run->inputTokens,
            'outputTokens' => $run->outputTokens,
            'cacheReadTokens' => $run->cacheReadTokens,
            'cacheWriteTokens' => $run->cacheWriteTokens,
            'cost' => $run->getCost(),
            'currency' => $run->currency,
            'durationMs' => $run->durationMs,
            'toolCalls' => \count($run->getToolCalls()),
            'error' => $run->error,
        ];
    }
}
