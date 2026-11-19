<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant\Tool;

use Eekes\Sulu\AiContentBundle\Application\Content\IconFinderInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('search_icons', 'Finds icons of an icon set by name, for an icon field. Returns at most 50 icon names.')]
final class SearchIconsTool
{
    public function __construct(
        private readonly IconFinderInterface $iconFinder,
    ) {
    }

    /**
     * @param string $iconSet The icon set of the field
     * @param string $query Part of the name, in English (for example "phone", "leaf", "arrow"); empty lists the first ones
     *
     * @return list<string>
     */
    public function __invoke(string $iconSet, string $query = ''): array
    {
        $query = mb_strtolower(trim($query));
        $icons = $this->iconFinder->icons($iconSet);

        if ($query !== '') {
            $icons = array_values(array_filter($icons, static fn (string $icon) => str_contains(mb_strtolower($icon), $query)));
        }

        return \array_slice($icons, 0, 50);
    }
}
