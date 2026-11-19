<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Message;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantRequest;
use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantRunner;
use Eekes\Sulu\AiContentBundle\Domain\Model\RunStatus;
use Eekes\Sulu\AiContentBundle\Domain\Repository\AiRunRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunAssistantHandler
{
    public function __construct(
        private readonly AiRunRepository $runRepository,
        private readonly AssistantRunner $runner,
    ) {
    }

    public function __invoke(RunAssistant $message): void
    {
        $run = $this->runRepository->findById($message->runId);

        // a run is only started once: a redelivered message finds it running or finished
        if ($run === null || $run->conversation === null || $run->getStatus() !== RunStatus::Pending) {
            return;
        }

        // run after the response in a web process: the model may take longer than a request is
        // normally allowed to, and an editor who reloads or leaves the page must not stop it (PHP-FPM
        // has closed the connection already; other servers could abort on a lost client)
        if (\PHP_SAPI !== 'cli') {
            set_time_limit(300);
            ignore_user_abort(true);
        }

        $this->runner->run($run, $run->conversation, AssistantRequest::fromArray($message->request));
    }
}
