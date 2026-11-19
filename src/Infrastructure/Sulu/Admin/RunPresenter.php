<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Admin;

use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;
use Eekes\Sulu\AiContentBundle\Domain\Entity\Conversation;
use Eekes\Sulu\AiContentBundle\Domain\Repository\AiRunRepository;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Security\Authentication\UserRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A run as the assistant panel polls it, and as the detail page of the usage log shows it.
 */
final class RunPresenter
{
    /**
     * The steps of a run with a text of their own (eekes_ai_content.progress.*).
     */
    private const array STEPS = ['reading_page', 'thinking', 'checking_content', 'checking_plan'];

    /**
     * The built-in tools with a text of their own (eekes_ai_content.progress.tool.*).
     */
    private const array TOOLS = ['search_pages', 'get_page', 'search_media', 'search_snippets', 'search_categories', 'search_tags', 'search_icons'];

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly AiRunRepository $runRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toTurn(AiRun $run, string $locale = 'en'): array
    {
        return [
            'id' => $run->id,
            'status' => $run->getStatus()->value,
            'finished' => $run->getStatus()->isFinished(),
            'progress' => $this->progress($run->progress, $locale),
            'toolCalls' => array_map(static fn (array $call) => $call['name'], $run->getToolCalls()),
            'reply' => $run->reply,
            'error' => $run->error,
            'usage' => $this->usage($run),
        ];
    }

    /**
     * What a run is doing, in the language of the editor's admin. A tool of the project is named;
     * anything else that is not a known code is shown as it is.
     */
    private function progress(?string $progress, string $locale): ?string
    {
        if ($progress === null) {
            return null;
        }

        if (str_starts_with($progress, 'tool:')) {
            $tool = substr($progress, \strlen('tool:'));

            return \in_array($tool, self::TOOLS, true)
                ? $this->translator->trans('eekes_ai_content.progress.tool.'.$tool, [], 'admin', $locale)
                : $this->translator->trans('eekes_ai_content.progress.tool', ['{name}' => $tool], 'admin', $locale);
        }

        if (str_starts_with($progress, 'describing:')) {
            return $this->translator->trans('eekes_ai_content.progress.describing', ['{name}' => substr($progress, \strlen('describing:'))], 'admin', $locale);
        }

        if (\in_array($progress, self::STEPS, true)) {
            return $this->translator->trans('eekes_ai_content.progress.'.$progress, [], 'admin', $locale);
        }

        return $progress;
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(AiRun $run): array
    {
        return [
            'model' => $run->model,
            'tokens' => $run->getTokens()->toArray(),
            'requests' => $run->requests,
            'cost' => $run->getCost(),
            'currency' => $run->currency,
            'durationMs' => $run->durationMs,
        ];
    }

    /**
     * The messages of a conversation, each reply with the usage of its run - what the panel shows
     * when a conversation is opened again.
     *
     * @return list<array<string, mixed>>
     */
    public function conversationMessages(Conversation $conversation): array
    {
        $runIds = array_values(array_unique(array_filter(array_map(static fn (array $message) => $message['runId'] ?? null, $conversation->getMessages()))));
        $runs = $this->runRepository->findByIds($runIds);
        $messages = [];

        foreach ($conversation->getMessages() as $message) {
            $run = $runs[$message['runId'] ?? ''] ?? null;
            $message['usage'] = $message['role'] === 'assistant' && $run !== null ? $this->usage($run) : null;
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * Everything about one run for the detail page of the usage log, with the conversation it is
     * part of. The labels are translated here; the page renders text, not form fields.
     *
     * @return array<string, mixed>
     */
    public function toDetail(AiRun $run, string $locale): array
    {
        $conversation = $run->conversation;

        return [
            'id' => $run->id,
            'action' => $run->getAction()->value,
            'actionLabel' => $this->translator->trans('eekes_ai_content.action.'.$run->getAction()->value, [], 'admin', $locale),
            'status' => $run->getStatus()->value,
            'statusLabel' => $this->translator->trans('eekes_ai_content.status.'.$run->getStatus()->value, [], 'admin', $locale),
            'createdAt' => $run->createdAt->format(\DATE_ATOM),
            'finishedAt' => $run->finishedAt?->format(\DATE_ATOM),
            'user' => $this->userName($run->userId),
            'webspaceKey' => $run->webspaceKey,
            'locale' => $run->locale,
            'formKey' => $run->formKey,
            'resourceId' => $run->resourceId,
            'modelKey' => $run->modelKey,
            'prompt' => $run->prompt,
            'reply' => $run->reply,
            'error' => $run->error,
            'toolCalls' => $run->getToolCalls(),
            'transcript' => $run->transcript,
            'usage' => $this->usage($run),
            'conversation' => $conversation === null ? null : [
                'id' => $conversation->id,
                'title' => $conversation->getTitle(),
                'messages' => $this->conversationMessages($conversation),
            ],
        ];
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $user = $this->userRepository->findUserById($userId);

        return $user instanceof User ? $user->getFullName() : (string) $userId;
    }
}
