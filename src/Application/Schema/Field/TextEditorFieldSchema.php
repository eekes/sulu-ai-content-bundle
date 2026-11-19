<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\HtmlCleaner;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;

/**
 * Rich text. Internal links use Sulu's own <sulu-link> element, so they keep working when the
 * target page moves.
 */
final class TextEditorFieldSchema implements FieldSchemaInterface
{
    public function __construct(
        private readonly HtmlCleaner $htmlCleaner,
    ) {
    }

    public function supports(FieldDescription $field): bool
    {
        return $field->type === 'text_editor';
    }

    public function schema(FieldDescription $field, SchemaContext $context): array
    {
        return [
            'type' => 'string',
            'description' => 'HTML. Allowed: <p>, <h2>-<h4>, <strong>, <em>, <ul>, <ol>, <li>, <blockquote>, <br>, <a href="https://..."> for external links and <sulu-link provider="page" href="PAGE-UUID">text</sulu-link> for links to pages of this website (find the uuid with the search_pages tool). No inline styles, classes or <h1>.',
        ];
    }

    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): string
    {
        if (!\is_string($value)) {
            throw new InvalidValueException('Expected an HTML string.');
        }

        return $this->htmlCleaner->clean($value);
    }
}
