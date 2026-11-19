<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\IconFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;

/**
 * Icons from a Sulu icon set (single_icon_selection with an "icon_set" param). The model picks an
 * icon by its name: from the list in the schema, or - for a large set - with search_icons.
 */
final class IconFieldSchema implements FieldSchemaInterface
{
    /**
     * Above this many icons the names are not listed in the schema: they would cost more tokens
     * than they help. The model searches them instead.
     */
    public const int LIST_LIMIT = 300;

    public function __construct(
        private readonly IconFinderInterface $iconFinder,
    ) {
    }

    public static function iconSet(FieldDescription $field): ?string
    {
        $iconSet = $field->params['icon_set'] ?? null;

        return \is_string($iconSet) && $iconSet !== '' ? $iconSet : null;
    }

    public function supports(FieldDescription $field): bool
    {
        $iconSet = self::iconSet($field);

        return $field->type === 'single_icon_selection' && $iconSet !== null && $this->iconFinder->icons($iconSet) !== [];
    }

    public function schema(FieldDescription $field, SchemaContext $context): array
    {
        $iconSet = (string) self::iconSet($field);
        $icons = $this->iconFinder->icons($iconSet);

        if (\count($icons) <= self::LIST_LIMIT) {
            return ['type' => 'string', 'enum' => $icons, 'description' => 'The name of the icon that fits the text best.'];
        }

        return ['type' => 'string', 'description' => \sprintf('The name of the icon that fits the text best: find it with search_icons, icon set "%s".', $iconSet)];
    }

    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): string
    {
        $iconSet = (string) self::iconSet($field);

        if (!\is_string($value) || !\in_array($value, $this->iconFinder->icons($iconSet), true)) {
            throw new InvalidValueException(\sprintf('"%s" is not an icon of the set "%s". Use search_icons to find one.', \is_scalar($value) ? $value : \gettype($value), $iconSet));
        }

        return $value;
    }
}
