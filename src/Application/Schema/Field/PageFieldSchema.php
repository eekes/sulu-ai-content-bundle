<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;

/**
 * Page selections and links. Internal targets are page uuids the model found with search_pages;
 * external links must be absolute.
 */
final class PageFieldSchema implements FieldSchemaInterface
{
    public function __construct(
        private readonly PageFinderInterface $pageFinder,
    ) {
    }

    public function supports(FieldDescription $field): bool
    {
        return \in_array($field->type, ['single_page_selection', 'page_selection', 'link'], true);
    }

    public function schema(FieldDescription $field, SchemaContext $context): array
    {
        return match ($field->type) {
            'single_page_selection' => ['type' => 'string', 'description' => 'The uuid of a page, from search_pages.'],
            'page_selection' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Uuids of pages, from search_pages.'],
            default => [
                'type' => 'object',
                'properties' => [
                    'provider' => ['type' => 'string', 'enum' => ['page', 'external']],
                    'href' => ['type' => 'string', 'description' => 'A page uuid from search_pages for "page", an absolute URL for "external". No anchors such as "#contact" and no made-up paths: when no fitting page exists, leave the whole link out.'],
                    'title' => ['type' => 'string', 'description' => 'Optional link text.'],
                    'target' => ['type' => 'string', 'enum' => ['_self', '_blank']],
                ],
                'required' => ['provider', 'href'],
            ],
        };
    }

    /**
     * @return array<string, string>|list<string>|string
     */
    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): string|array
    {
        return match ($field->type) {
            'single_page_selection' => $this->uuids([$value])[0],
            'page_selection' => \is_array($value) ? $this->uuids(array_values($value)) : throw new InvalidValueException('Expected a list of page uuids.'),
            default => $this->link($value, $context),
        };
    }

    /**
     * @return array<string, string>
     */
    private function link(mixed $value, SchemaContext $context): array
    {
        if (!\is_array($value) || !\is_string($value['href'] ?? null)) {
            throw new InvalidValueException('Expected {"provider": "page"|"external", "href": "..."}.');
        }

        $provider = $value['provider'] ?? null;
        $href = trim($value['href']);

        if ($provider === 'page') {
            $href = $this->uuids([$href])[0];
        } elseif ($provider === 'external') {
            if (preg_match('#^(https?://|mailto:|tel:)#i', $href) !== 1) {
                throw new InvalidValueException(\sprintf('"%s" is not an absolute URL.', $href));
            }
        } else {
            throw new InvalidValueException('The provider must be "page" or "external".');
        }

        $link = ['provider' => $provider, 'href' => $href, 'locale' => $context->locale];

        foreach (['title', 'target'] as $key) {
            if (\is_string($value[$key] ?? null) && $value[$key] !== '') {
                $link[$key] = $value[$key];
            }
        }

        return $link;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function uuids(array $values): array
    {
        $uuids = [];

        foreach ($values as $value) {
            if (!\is_string($value) || $value === '') {
                throw new InvalidValueException('Expected a page uuid.');
            }

            $uuids[] = $value;
        }

        $missing = array_diff($uuids, $this->pageFinder->existing($uuids));

        if ($missing !== []) {
            throw new InvalidValueException(\sprintf('Unknown page uuids: %s. Use search_pages to find real ones.', implode(', ', $missing)));
        }

        return $uuids;
    }
}
