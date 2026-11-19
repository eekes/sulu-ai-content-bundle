<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Catalog;

/**
 * What a template or block is for, in words the model reads before it picks one: the catalog
 * entry of one template or one block.
 *
 * "fingerprint" is the structure the text was written for and "generated" the hash of the text
 * as the model wrote it. Together they tell the analyze command whether a block changed since,
 * and whether a person has edited the text (which it then never overwrites without --force).
 */
final readonly class Guide
{
    /**
     * @param list<string> $excludeFields properties the assistant never fills in
     */
    public function __construct(
        public string $description = '',
        public string $useWhen = '',
        public string $avoidWhen = '',
        public string $tips = '',
        public array $excludeFields = [],
        public ?string $fingerprint = null,
        public ?string $generated = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): string => \is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        $excludeFields = \is_array($data['exclude_fields'] ?? null) ? array_values(array_filter($data['exclude_fields'], 'is_string')) : [];

        return new self(
            $string('description'),
            $string('use_when'),
            $string('avoid_when'),
            $string('tips'),
            $excludeFields,
            \is_string($data['fingerprint'] ?? null) ? $data['fingerprint'] : null,
            \is_string($data['generated'] ?? null) ? $data['generated'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // the text keys are always written, also empty, so the file shows what can be filled in
        return array_filter([
            'description' => $this->description,
            'use_when' => $this->useWhen,
            'avoid_when' => $this->avoidWhen,
            'tips' => $this->tips,
            'exclude_fields' => $this->excludeFields,
            'fingerprint' => $this->fingerprint,
            'generated' => $this->generated,
        ], static fn (mixed $value) => $value !== null);
    }

    public function textHash(): string
    {
        return substr(hash('sha256', $this->description."\n".$this->useWhen."\n".$this->avoidWhen."\n".$this->tips), 0, 12);
    }

    /**
     * Whether a person changed the text after the model wrote it.
     */
    public function isEdited(): bool
    {
        return $this->generated === null ? $this->description !== '' : $this->generated !== $this->textHash();
    }

    public function isEmpty(): bool
    {
        return $this->description === '' && $this->useWhen === '' && $this->avoidWhen === '' && $this->tips === '';
    }

    public function withFingerprint(string $fingerprint): self
    {
        return new self($this->description, $this->useWhen, $this->avoidWhen, $this->tips, $this->excludeFields, $fingerprint, $this->generated);
    }

    /**
     * The text as the model wrote it, keeping what a person configured: the excluded fields.
     */
    public function withGeneratedText(self $text, string $fingerprint): self
    {
        $guide = new self($text->description, $text->useWhen, $text->avoidWhen, $text->tips, $this->excludeFields, $fingerprint);

        return new self($guide->description, $guide->useWhen, $guide->avoidWhen, $guide->tips, $guide->excludeFields, $fingerprint, $guide->textHash());
    }
}
