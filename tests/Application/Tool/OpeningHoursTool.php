<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Application\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('opening_hours', 'Returns the opening hours of the shop.')]
final class OpeningHoursTool
{
    public int $calls = 0;

    public function __invoke(): string
    {
        ++$this->calls;

        return 'Monday to Friday, 9:00 to 18:00';
    }
}
