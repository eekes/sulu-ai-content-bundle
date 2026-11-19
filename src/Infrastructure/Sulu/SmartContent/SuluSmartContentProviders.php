<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\SmartContent;

use Eekes\Sulu\AiContentBundle\Application\Content\SmartContentDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\SmartContentProvidersInterface;
use Psr\Container\ContainerInterface;
use Sulu\Bundle\AdminBundle\SmartContent\SmartContentProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Describes Sulu's smart content providers from their own configuration - the same one the
 * filter overlay of the admin is built from - so a project's own provider works as well.
 */
final class SuluSmartContentProviders implements SmartContentProvidersInterface
{
    /**
     * @var array<string, null|SmartContentDescription>
     */
    private array $descriptions = [];

    /**
     * @param ContainerInterface $providers the providers tagged "sulu_content.smart_content_provider", by type
     */
    public function __construct(
        private readonly ContainerInterface $providers,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function describe(string $provider): ?SmartContentDescription
    {
        if (!\array_key_exists($provider, $this->descriptions)) {
            $this->descriptions[$provider] = $this->load($provider);
        }

        return $this->descriptions[$provider];
    }

    private function load(string $provider): ?SmartContentDescription
    {
        if (!$this->providers->has($provider)) {
            return null;
        }

        $service = $this->providers->get($provider);

        if (!$service instanceof SmartContentProviderInterface) {
            return null;
        }

        $configuration = $service->getConfiguration();
        $types = [];

        foreach ($configuration->hasTypes() ? $configuration->getTypes() ?? [] : [] as $type) {
            // a type is built as ("title", "type")
            $value = $type->getValue();

            if (\is_string($value) && $value !== '') {
                $types[] = ['value' => $value, 'label' => $this->label($type->getName(), $value)];
            }
        }

        $sorting = [];

        foreach ($configuration->hasSorting() ? $configuration->getSorting() ?? [] : [] as $sort) {
            // a sorting is built as ("column", "title"); the empty column is the provider's default order
            $column = $sort->getName();

            if ($column !== '') {
                $sorting[] = ['value' => $column, 'label' => $this->label($sort->getValue(), $column)];
            }
        }

        return new SmartContentDescription(
            provider: $provider,
            dataSourceResourceKey: $configuration->hasDatasource() ? $configuration->getDatasourceResourceKey() : null,
            tags: $configuration->hasTags(),
            categories: $configuration->hasCategories(),
            types: $types,
            sorting: $sorting,
            limit: $configuration->hasLimit(),
        );
    }

    private function label(mixed $label, string $fallback): string
    {
        if (!\is_string($label) || $label === '') {
            return $fallback;
        }

        return $this->translator?->trans($label, [], 'admin', 'en') ?? $label;
    }
}
