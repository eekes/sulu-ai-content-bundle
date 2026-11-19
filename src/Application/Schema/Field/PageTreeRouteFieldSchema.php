<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;

/**
 * The URL of an article (or anything else with a page_tree_route): it sits below a page, such as
 * "/news". The assistant picks that page; the rest of the URL is made from the title in the
 * admin, the way Sulu's own field makes it.
 */
final class PageTreeRouteFieldSchema implements FieldSchemaInterface
{
    public function __construct(
        private readonly PageFinderInterface $pageFinder,
    ) {
    }

    public function supports(FieldDescription $field): bool
    {
        return $field->type === 'page_tree_route';
    }

    public function schema(FieldDescription $field, SchemaContext $context): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page' => ['type' => 'string', 'description' => 'The uuid of the page the URL sits below, from the pages of the website.'],
            ],
            'required' => ['page'],
            'description' => 'Where the URL sits: below which page. The rest of the URL is made from the title; never write it yourself.',
        ];
    }

    /**
     * @return array{page: array{uuid: string, path: string}}
     */
    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): array
    {
        $page = \is_array($value) ? $value['page'] ?? null : $value;

        if (\is_array($page)) {
            $page = $page['uuid'] ?? null;
        }

        if (!\is_string($page) || trim($page) === '') {
            throw new InvalidValueException('Expected {"page": "<page uuid>"}.');
        }

        $path = $this->pageFinder->url(trim($page), $context->locale);

        if ($path === null) {
            throw new InvalidValueException(\sprintf('The page "%s" does not exist or has no URL in this language. Use a page of the site structure.', $page));
        }

        return ['page' => ['uuid' => trim($page), 'path' => $path]];
    }
}
