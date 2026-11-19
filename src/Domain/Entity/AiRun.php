<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Eekes\Sulu\AiContentBundle\Domain\Model\AiAction;
use Eekes\Sulu\AiContentBundle\Domain\Model\RunStatus;
use Eekes\Sulu\AiContentBundle\Domain\Model\TokenTotals;
use Eekes\Sulu\AiContentBundle\Domain\Repository\AiRunRepository;
use Symfony\Component\Uid\Uuid;

/**
 * One question to the model and everything it cost: the action, who asked, on which page, which
 * model answered, the tokens of every request summed up, the estimated price, how long it took,
 * which tools it used and, unless switched off, the full prompt and answer.
 *
 * A run is created before the model is called, so a run that never finishes still shows up.
 */
#[ORM\Entity(repositoryClass: AiRunRepository::class)]
#[ORM\Table(name: 'eekes_ai_run')]
#[ORM\Index(name: 'idx_eekes_ai_run_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_eekes_ai_run_status', columns: ['status'])]
class AiRun
{
    public const string RESOURCE_KEY = 'eekes_ai_content_runs';
    public const string LIST_KEY = 'eekes_ai_content_runs';

    /**
     * How long an unfinished run counts as still running, in seconds.
     */
    public const int BUSY_FOR_AT_MOST = 900;

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Conversation $conversation;

    #[ORM\Column(name: 'ai_action', length: 32)]
    private string $action;

    #[ORM\Column(length: 16)]
    private string $status = RunStatus::Pending->value;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $webspaceKey = null;

    #[ORM\Column(length: 16, nullable: true)]
    public ?string $locale = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $formKey = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $resourceId = null;

    #[ORM\Column(nullable: true)]
    public ?int $userId = null;

    /**
     * The key the model is configured under ("smart", "fast"), so the log stays readable after a
     * model is swapped for a newer one.
     */
    #[ORM\Column(length: 64)]
    public string $modelKey;

    #[ORM\Column(length: 128)]
    public string $model;

    #[ORM\Column]
    public int $inputTokens = 0;

    #[ORM\Column]
    public int $outputTokens = 0;

    #[ORM\Column]
    public int $cacheReadTokens = 0;

    #[ORM\Column]
    public int $cacheWriteTokens = 0;

    #[ORM\Column]
    public int $thinkingTokens = 0;

    #[ORM\Column]
    public int $totalTokens = 0;

    /**
     * How many times the model was called: one, plus one per round of tool calls.
     */
    #[ORM\Column]
    public int $requests = 0;

    /**
     * The estimated price, to six decimals. Null when no price is configured for the model: an
     * unknown price is not a free run.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 6, nullable: true)]
    private ?string $cost = null;

    #[ORM\Column(length: 3)]
    public string $currency;

    #[ORM\Column(nullable: true)]
    public ?int $durationMs = null;

    /**
     * What the run is doing now, shown in the panel while the editor waits: a code such as
     * "thinking" or "tool:search_pages", translated for the editor by the admin.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $progress = null;

    /**
     * @var list<array{name: string, arguments: array<string, mixed>, at: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $toolCalls = [];

    /**
     * What the editor asked, in their words.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $prompt = null;

    /**
     * The parsed reply.
     *
     * @var null|array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $reply = null;

    /**
     * Every message sent to and received from the model, when prompt logging is on.
     *
     * @var null|list<array{role: string, content: string}>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $transcript = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $error = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    public function __construct(AiAction $action, string $modelKey, string $model, string $currency, ?Conversation $conversation = null)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->action = $action->value;
        $this->modelKey = $modelKey;
        $this->model = $model;
        $this->currency = $currency;
        $this->conversation = $conversation;
        $this->createdAt = new \DateTimeImmutable();

        if ($conversation !== null) {
            $this->webspaceKey = $conversation->webspaceKey;
            $this->locale = $conversation->locale;
            $this->formKey = $conversation->formKey;
            $this->resourceId = $conversation->resourceId;
            $this->userId = $conversation->userId;
        }
    }

    public function getAction(): AiAction
    {
        return AiAction::from($this->action);
    }

    public function getStatus(): RunStatus
    {
        return RunStatus::from($this->status);
    }

    /**
     * Whether the run is still being worked on: not finished, and started recently enough that a
     * worker can still be at it. A run older than that was lost (a worker that died, a request
     * that was killed) and no longer holds up its conversation.
     */
    public function isBusy(\DateTimeImmutable $now): bool
    {
        return !$this->getStatus()->isFinished() && $this->createdAt > $now->modify(\sprintf('-%d seconds', self::BUSY_FOR_AT_MOST));
    }

    public function start(): void
    {
        $this->status = RunStatus::Running->value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function recordToolCall(string $name, array $arguments): void
    {
        $this->toolCalls[] = ['name' => $name, 'arguments' => $arguments, 'at' => (new \DateTimeImmutable())->format(\DATE_ATOM)];
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>, at: string}>
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    public function addUsage(TokenTotals $tokens, int $requests): void
    {
        $this->inputTokens += $tokens->input;
        $this->outputTokens += $tokens->output;
        $this->cacheReadTokens += $tokens->cacheRead;
        $this->cacheWriteTokens += $tokens->cacheWrite;
        $this->thinkingTokens += $tokens->thinking;
        $this->totalTokens += $tokens->total > 0 ? $tokens->total : $tokens->input + $tokens->output;
        $this->requests += $requests;
    }

    public function getTokens(): TokenTotals
    {
        return new TokenTotals($this->inputTokens, $this->outputTokens, $this->cacheReadTokens, $this->cacheWriteTokens, $this->thinkingTokens, $this->totalTokens);
    }

    public function setCostMicros(?int $costMicros): void
    {
        $this->cost = $costMicros === null ? null : \sprintf('%d.%06d', intdiv($costMicros, 1_000_000), $costMicros % 1_000_000);
    }

    public function getCostMicros(): ?int
    {
        return $this->cost === null ? null : (int) round((float) $this->cost * 1_000_000);
    }

    public function getCost(): ?float
    {
        return $this->cost === null ? null : (float) $this->cost;
    }

    /**
     * @param array<string, mixed> $reply
     */
    public function succeed(array $reply): void
    {
        $this->status = RunStatus::Succeeded->value;
        $this->reply = $reply;
        $this->progress = null;
        $this->finish();
    }

    public function fail(string $error): void
    {
        $this->status = RunStatus::Failed->value;
        $this->error = $error;
        $this->progress = null;
        $this->finish();
    }

    private function finish(): void
    {
        $this->finishedAt = new \DateTimeImmutable();
        $this->durationMs = (int) round(((float) $this->finishedAt->format('U.u') - (float) $this->createdAt->format('U.u')) * 1000);
    }
}
