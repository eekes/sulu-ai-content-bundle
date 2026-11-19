<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Symfony\EventListener;

use Eekes\Sulu\AiContentBundle\Application\Message\RunAssistant;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches the runs of the request once the response has been sent.
 *
 * Without an async transport Messenger handles a run inside the request that sends it, and the
 * panel could not poll before the model was done - a minute of "thinking" without any progress.
 * Dispatched on kernel.terminate, the request returns at once (PHP-FPM sends the response first),
 * the run happens afterwards, and every poll shows how far it is. With an async transport this
 * only moves a quick dispatch to the end of the request.
 */
final class DeferredRunDispatcher implements EventSubscriberInterface
{
    /**
     * @var list<RunAssistant>
     */
    private array $queue = [];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'dispatch'];
    }

    public function defer(RunAssistant $message): void
    {
        $this->queue[] = $message;
    }

    public function dispatch(): void
    {
        $queue = $this->queue;
        $this->queue = [];

        foreach ($queue as $message) {
            try {
                $this->messageBus->dispatch($message);
            } catch (\Throwable $exception) {
                $this->logger->error('Dispatching AI run {run} failed: {error}', ['run' => $message->runId, 'error' => $exception->getMessage(), 'exception' => $exception]);
            }
        }
    }
}
