<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema;

/**
 * A value the model sent back that cannot be stored. The message is written for the model: it
 * is sent back so the model can correct itself.
 */
final class InvalidValueException extends \InvalidArgumentException
{
}
