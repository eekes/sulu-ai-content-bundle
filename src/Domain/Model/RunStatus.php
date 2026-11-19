<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Domain\Model;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
