<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema;

final readonly class SanitizedContent
{
    /**
     * @param array<string, mixed> $data
     * @param list<string> $errors problems the model can fix
     * @param list<string> $manual required fields only the editor can fill
     */
    public function __construct(
        public array $data,
        public array $errors,
        public array $manual = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
