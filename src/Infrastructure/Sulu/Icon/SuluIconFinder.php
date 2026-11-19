<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Icon;

use Eekes\Sulu\AiContentBundle\Application\Content\IconFinderInterface;
use Sulu\Bundle\AdminBundle\Icon\IconProviderInterface;

/**
 * Reads icon sets the way Sulu's icon overlay does: "sulu_admin.icon_sets" maps a set to
 * "<provider>://<path>", the provider (svg, icomoon or a project's own) lists its icons.
 */
final class SuluIconFinder implements IconFinderInterface
{
    /**
     * @var array<string, list<string>>
     */
    private array $icons = [];

    /**
     * @param array<string, string> $iconSets
     * @param iterable<string, IconProviderInterface> $providers by type
     */
    public function __construct(
        private readonly array $iconSets,
        private readonly iterable $providers,
    ) {
    }

    public function icons(string $iconSet): array
    {
        return $this->icons[$iconSet] ??= $this->load($iconSet);
    }

    /**
     * @return list<string>
     */
    private function load(string $iconSet): array
    {
        $definition = $this->iconSets[$iconSet] ?? null;

        if ($definition === null || !str_contains($definition, '://')) {
            return [];
        }

        [$type, $path] = explode('://', $definition, 2);

        foreach ($this->providers as $providerType => $provider) {
            if ($providerType !== $type) {
                continue;
            }

            try {
                $ids = array_map(static fn (array $icon) => $icon['id'], $provider->getIcons($path));
            } catch (\Throwable) {
                // a missing folder or file: the field is left to the editor
                return [];
            }

            $ids = array_values(array_unique(array_filter($ids, static fn (string $id) => $id !== '')));
            sort($ids);

            return $ids;
        }

        return [];
    }
}
