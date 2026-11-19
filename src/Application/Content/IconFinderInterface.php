<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Content;

/**
 * The icons of an icon set, for single_icon_selection fields.
 */
interface IconFinderInterface
{
    /**
     * The ids (names) of the icons of the set, sorted; empty for a set that does not exist.
     *
     * @return list<string>
     */
    public function icons(string $iconSet): array;
}
