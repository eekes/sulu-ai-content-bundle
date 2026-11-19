<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Domain;

use Eekes\Sulu\AiContentBundle\Domain\Entity\WebspaceContext;
use PHPUnit\Framework\TestCase;

final class WebspaceContextTest extends TestCase
{
    public function testTheSameQuestionReplacesItsAnswer(): void
    {
        $context = new WebspaceContext('website', 'en');
        $context->rememberFact('What is the name of the bakery?', 'Brood');
        $context->rememberFact('Opening hours?', 'Mon-Fri');
        $context->rememberFact('what is the name of the bakery', ' Brood & Zo ');
        $context->rememberFact('Empty?', '   ');

        self::assertSame([
            ['question' => 'Opening hours?', 'answer' => 'Mon-Fri'],
            ['question' => 'what is the name of the bakery', 'answer' => 'Brood & Zo'],
        ], $context->getFacts());
        self::assertFalse($context->isEmpty());
    }
}
