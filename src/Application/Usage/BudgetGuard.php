<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Usage;

use Eekes\Sulu\AiContentBundle\Domain\Repository\AiRunRepository;
use Psr\Clock\ClockInterface;

/**
 * Stops new runs once the estimated cost of this calendar month reaches the configured limit,
 * for the whole installation and, optionally, per webspace.
 *
 * It is a brake, not an invoice: it only knows the estimate of runs that have a price, and a run
 * that started before the limit was reached still finishes.
 */
final class BudgetGuard
{
    public function __construct(
        private readonly AiRunRepository $runRepository,
        private readonly ClockInterface $clock,
        private readonly ?float $monthlyLimit,
        private readonly ?float $monthlyLimitPerWebspace,
    ) {
    }

    /**
     * @throws BudgetExceededException
     */
    public function check(?string $webspaceKey): void
    {
        $since = $this->clock->now()->modify('first day of this month')->setTime(0, 0);

        if ($this->monthlyLimit !== null && $this->runRepository->sumCostMicrosSince($since) >= $this->monthlyLimit * 1_000_000) {
            throw new BudgetExceededException($this->monthlyLimit, null);
        }

        if ($webspaceKey !== null && $this->monthlyLimitPerWebspace !== null && $this->runRepository->sumCostMicrosSince($since, $webspaceKey) >= $this->monthlyLimitPerWebspace * 1_000_000) {
            throw new BudgetExceededException($this->monthlyLimitPerWebspace, $webspaceKey);
        }
    }

    /**
     * @return array{spent: float, limit: null|float, since: \DateTimeImmutable}
     */
    public function status(): array
    {
        $since = $this->clock->now()->modify('first day of this month')->setTime(0, 0);

        return [
            'spent' => $this->runRepository->sumCostMicrosSince($since) / 1_000_000,
            'limit' => $this->monthlyLimit,
            'since' => $since,
        ];
    }
}
