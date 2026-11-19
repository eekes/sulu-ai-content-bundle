<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;

/**
 * Plain text: one line, several lines, and the text inputs with a format.
 */
final class TextFieldSchema implements FieldSchemaInterface
{
    private const array FORMATS = [
        'text_line' => 'Plain text on one line: no HTML, no Markdown, no links.',
        'text_area' => 'Plain text, line breaks allowed: no HTML, no Markdown, no links.',
        'email' => 'An e-mail address.',
        'url' => 'An absolute URL starting with https://.',
        'phone' => 'A phone number.',
        'color' => 'A hex colour like #1a2b3c.',
        'date' => 'A date as YYYY-MM-DD.',
        'time' => 'A time as HH:MM.',
    ];

    public function supports(FieldDescription $field): bool
    {
        return \array_key_exists($field->type, self::FORMATS);
    }

    public function schema(FieldDescription $field, SchemaContext $context): array
    {
        return ['type' => 'string', 'description' => self::FORMATS[$field->type]];
    }

    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): string
    {
        if (!\is_scalar($value)) {
            throw new InvalidValueException('Expected a string.');
        }

        $value = trim((string) $value);

        if (\in_array($field->type, ['text_line', 'text_area'], true) && self::hasLink($value)) {
            throw new InvalidValueException('This is a plain text field: it cannot hold a link. Write the text without it, and tell the editor in your message that this field cannot link.');
        }

        return match ($field->type) {
            'text_line' => trim((string) preg_replace('/\s+/u', ' ', strip_tags($value))),
            'text_area' => strip_tags($value),
            'email' => filter_var($value, \FILTER_VALIDATE_EMAIL) !== false ? $value : throw new InvalidValueException(\sprintf('"%s" is not an e-mail address.', $value)),
            'url' => preg_match('#^https?://#i', $value) === 1 ? $value : throw new InvalidValueException(\sprintf('"%s" is not an absolute URL.', $value)),
            'color' => preg_match('/^#[0-9a-f]{3,8}$/i', $value) === 1 ? $value : throw new InvalidValueException(\sprintf('"%s" is not a hex colour.', $value)),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : throw new InvalidValueException(\sprintf('"%s" is not a YYYY-MM-DD date.', $value)),
            'time' => preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) === 1 ? $value : throw new InvalidValueException(\sprintf('"%s" is not a HH:MM time.', $value)),
            default => strip_tags($value),
        };
    }

    /**
     * A Markdown or HTML link, which a plain text field would show as it is or lose.
     */
    private static function hasLink(string $value): bool
    {
        return preg_match('/\[[^\]]+\]\([^)]+\)|<a\s/i', $value) === 1;
    }
}
