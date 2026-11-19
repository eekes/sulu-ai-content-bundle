<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant;

use Eekes\Sulu\AiContentBundle\Application\Content\WebspaceAccessInterface;
use Eekes\Sulu\AiContentBundle\Domain\Entity\Conversation;
use Eekes\Sulu\AiContentBundle\Domain\Entity\WebspaceContext;
use Eekes\Sulu\AiContentBundle\Domain\Repository\WebspaceContextRepository;

/**
 * Keeps the answers to questions the assistant marked "remember" in the AI context of the
 * webspace, in the locale of the conversation. From then on they are in every prompt, and editors
 * see and correct them in the AI context tab.
 */
class FactMemory
{
    public function __construct(
        private readonly WebspaceContextRepository $contextRepository,
        private readonly WebspaceAccessInterface $webspaceAccess,
    ) {
    }

    /**
     * @param list<array{question: string, answer: string, remember: bool}> $answers
     *
     * @return int how many facts were kept
     */
    public function remember(Conversation $conversation, array $answers): int
    {
        $facts = array_filter($answers, static fn (array $answer) => $answer['remember'] && trim($answer['answer']) !== '');

        // without a webspace there is no AI context to keep them in; an editor who does not work in
        // the webspace does not write to its context
        if ($facts === [] || $conversation->webspaceKey === null || !$this->webspaceAccess->canUseWebspace($conversation->userId, $conversation->webspaceKey, $conversation->locale)) {
            return 0;
        }

        $context = $this->contextRepository->findOneByWebspace($conversation->webspaceKey, $conversation->locale)
            ?? new WebspaceContext($conversation->webspaceKey, $conversation->locale);

        foreach ($facts as $fact) {
            $context->rememberFact($fact['question'], $fact['answer']);
        }

        $this->contextRepository->save($context);

        return \count($facts);
    }
}
