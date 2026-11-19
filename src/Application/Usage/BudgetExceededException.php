<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Usage;

final class BudgetExceededException extends \RuntimeException
{
    public function __construct(
        public readonly float $limit,
        public readonly ?string $webspaceKey,
    ) {
        parent::__construct($webspaceKey === null
            ? \sprintf('The monthly AI budget of %.2f has been reached.', $limit)
            : \sprintf('The monthly AI budget of %.2f for the webspace "%s" has been reached.', $limit, $webspaceKey));
    }
}
