<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Metadata;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TemplateInspectorInterface;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;
use Sulu\Bundle\AdminBundle\Exception\MetadataNotFoundException;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;

/**
 * Reads templates through the same form metadata the admin renders, so XML partials, global
 * blocks and form metadata visitors of the project are all applied: what the assistant sees is
 * exactly the form the editor sees.
 */
final class SuluTemplateInspector implements TemplateInspectorInterface
{
    /**
     * @var array<string, array<string, FormMetadata>>
     */
    private array $globalBlocks = [];

    public function __construct(
        private readonly MetadataProviderRegistry $metadataProviderRegistry,
    ) {
    }

    public function getTemplates(string $formKey, string $locale, ?string $webspaceKey = null): array
    {
        $metadata = $this->loadTyped($formKey, $locale, $webspaceKey === null ? [] : ['webspace' => $webspaceKey]);

        if ($metadata === null) {
            return [];
        }

        $templates = [];

        foreach ($metadata->getForms() as $key => $form) {
            $templates[] = new TypeDescription((string) $key, $form->getTitle($locale), $this->describeItems($form->getItems(), $locale));
        }

        return $templates;
    }

    public function getTemplate(string $formKey, string $templateKey, string $locale, ?string $webspaceKey = null): ?TypeDescription
    {
        foreach ($this->getTemplates($formKey, $locale, $webspaceKey) as $template) {
            if ($template->key === $templateKey) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function loadTyped(string $key, string $locale, array $options): ?TypedFormMetadata
    {
        try {
            $metadata = $this->metadataProviderRegistry->getMetadataProvider('form')->getMetadata($key, $locale, $options);
        } catch (MetadataNotFoundException) {
            return null;
        }

        return $metadata instanceof TypedFormMetadata ? $metadata : null;
    }

    /**
     * @param array<ItemMetadata> $items
     *
     * @return list<FieldDescription>
     */
    private function describeItems(array $items, string $locale): array
    {
        $fields = [];

        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                array_push($fields, ...$this->describeItems($item->getItems(), $locale));

                continue;
            }

            if ($item instanceof FieldMetadata) {
                $fields[] = $this->describeField($item, $locale);
            }
        }

        return $fields;
    }

    private function describeField(FieldMetadata $field, string $locale): FieldDescription
    {
        $params = [];
        $choices = [];

        foreach ($field->getOptions() as $option) {
            $name = $option->getName();

            if (!\is_string($name)) {
                continue;
            }

            $params[$name] = self::optionValue($option, $locale);

            if ($name === 'values' && \is_array($option->getValue())) {
                $choices = self::choices($option->getValue(), $locale);
            }
        }

        $types = [];

        foreach ($field->getTypes() as $typeKey => $type) {
            $types[] = $this->describeType((string) $typeKey, $type, $locale);
        }

        return new FieldDescription(
            name: $field->getName(),
            type: $field->getType(),
            label: $field->getLabel($locale) ?? $field->getName(),
            description: $field->getDescription($locale),
            required: $field->isRequired(),
            params: $params,
            choices: $choices,
            types: $types,
            minOccurs: $field->getMinOccurs(),
            maxOccurs: $field->getMaxOccurs(),
            tags: array_values(array_map(static fn ($tag) => $tag->getName(), $field->getTags())),
        );
    }

    private function describeType(string $key, FormMetadata $type, string $locale): TypeDescription
    {
        $globalTag = $type->getTagsByName('sulu.global_block')[0] ?? null;
        $globalKey = $globalTag?->getAttribute('global_block');

        if (\is_string($globalKey)) {
            $global = $this->globalBlock($globalKey, $locale);

            if ($global !== null) {
                return new TypeDescription($key, $global->getTitle($locale), $this->describeItems($global->getItems(), $locale), $globalKey);
            }
        }

        return new TypeDescription($key, $type->getTitle($locale), $this->describeItems($type->getItems(), $locale));
    }

    private function globalBlock(string $key, string $locale): ?FormMetadata
    {
        if (!isset($this->globalBlocks[$locale])) {
            $this->globalBlocks[$locale] = [];

            foreach ($this->loadTyped('block', $locale, ['ignore_global_blocks' => true])?->getForms() ?? [] as $key => $form) {
                $this->globalBlocks[$locale][(string) $key] = $form;
            }
        }

        return $this->globalBlocks[$locale][$key] ?? null;
    }

    private static function optionValue(OptionMetadata $option, string $locale): mixed
    {
        $value = $option->getValue();

        if (!\is_array($value)) {
            return $value;
        }

        $values = [];

        foreach ($value as $child) {
            $name = $child->getName();
            $childValue = self::optionValue($child, $locale);
            $title = $child->getTitle($locale);

            if (\is_string($name) || \is_int($name)) {
                $values[$name] = $childValue ?? $title;
            } else {
                $values[] = $childValue ?? $title;
            }
        }

        return $values;
    }

    /**
     * @param array<mixed> $options
     *
     * @return list<array{value: string, label: string}>
     */
    private static function choices(array $options, string $locale): array
    {
        $choices = [];

        foreach ($options as $option) {
            if (!$option instanceof OptionMetadata) {
                continue;
            }

            $name = $option->getName();
            $value = $option->getValue();
            $choiceValue = \is_string($name) || \is_int($name) ? (string) $name : (\is_scalar($value) ? (string) $value : null);

            if ($choiceValue === null) {
                continue;
            }

            $choices[] = ['value' => $choiceValue, 'label' => $option->getTitle($locale) ?? $choiceValue];
        }

        return $choices;
    }
}
