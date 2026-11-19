<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema;

/**
 * Finds placeholders such as "[company name]" or "{year}" in the text the model wrote. A
 * placeholder means the model did not know a fact; it should have asked for it instead.
 */
final class PlaceholderFinder
{
    private const string PATTERN = '/\[[^\[\]<>\n]{2,40}\]|\{[^{}<>\n]{2,40}\}/u';

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    public function find(array $values): array
    {
        $found = [];

        array_walk_recursive($values, static function (mixed $value) use (&$found): void {
            if (!\is_string($value) || preg_match_all(self::PATTERN, strip_tags($value), $matches) === 0) {
                return;
            }

            foreach ($matches[0] as $match) {
                $found[] = $match;
            }
        });

        return array_values(array_unique($found));
    }
}
