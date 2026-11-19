<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Eekes\Sulu\AiContentBundle\Domain\Repository\ConversationRepository;
use Symfony\Component\Uid\Uuid;

/**
 * One assistant session on one page (or on a page that does not exist yet): the questions, the
 * outline and the drafts that went back and forth. Every turn is sent to the model again, so the
 * assistant remembers what was agreed, and every turn is a run in the usage log.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'eekes_ai_conversation')]
class Conversation
{
    public const string RESOURCE_KEY = 'eekes_ai_content_conversations';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $webspaceKey;

    #[ORM\Column(length: 16)]
    public string $locale;

    /**
     * The Sulu form the conversation belongs to: "page", "article" or "snippet".
     */
    #[ORM\Column(length: 64)]
    public string $formKey;

    /**
     * The id of the page, article or snippet; null while it has not been saved yet.
     */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $resourceId;

    #[ORM\Column(nullable: true)]
    public ?int $userId;

    /**
     * @var list<array{role: 'assistant'|'user', content: string, reply?: array<string, mixed>, runId?: string, at: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $messages = [];

    /**
     * How far the approved plans of a planner conversation got, by the run of the plan reply:
     * every item with its state, and the id of what was created. Kept so the planner shows it
     * again after a reload instead of offering the plan once more.
     *
     * @var null|array<string, list<array{item: array<string, mixed>, state: array<string, string>}>>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $planProgress = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(string $formKey, string $locale, ?string $webspaceKey, ?string $resourceId, ?int $userId)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->formKey = $formKey;
        $this->locale = $locale;
        $this->webspaceKey = $webspaceKey;
        $this->resourceId = $resourceId;
        $this->userId = $userId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * The first request of the editor, for the history list.
     */
    public function getTitle(): string
    {
        foreach ($this->messages as $message) {
            if ($message['role'] === 'user' && trim($message['content']) !== '') {
                $title = trim((string) preg_replace('/\s+/u', ' ', $message['content']));

                return mb_strlen($title) > 80 ? mb_substr($title, 0, 77).'...' : $title;
            }
        }

        return '';
    }

    /**
     * @param list<array{item: array<string, mixed>, state: array<string, string>}> $items
     */
    public function recordPlanProgress(string $runId, array $items): void
    {
        $this->planProgress ??= [];
        $this->planProgress[$runId] = $items;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return array<string, list<array{item: array<string, mixed>, state: array<string, string>}>>
     */
    public function getPlanProgress(): array
    {
        return $this->planProgress ?? [];
    }

    /**
     * Links a conversation that started on an unsaved page to the page once it has an id.
     */
    public function attachTo(string $resourceId): void
    {
        $this->resourceId ??= $resourceId;
    }

    public function addUserMessage(string $content, string $runId): void
    {
        $this->messages[] = ['role' => 'user', 'content' => $content, 'runId' => $runId, 'at' => self::now()];
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $reply the parsed reply, as the panel renders it
     */
    public function addAssistantMessage(string $content, array $reply, string $runId): void
    {
        $this->messages[] = ['role' => 'assistant', 'content' => $content, 'reply' => $reply, 'runId' => $runId, 'at' => self::now()];
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return list<array{role: 'assistant'|'user', content: string, reply?: array<string, mixed>, runId?: string, at: string}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format(\DATE_ATOM);
    }
}
