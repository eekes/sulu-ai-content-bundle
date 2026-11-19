<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Prompt;

/**
 * The language of a locale as the prompts name it: "English (locale "en")". A bare locale code
 * loses against a context, facts and pages in another language; the name of the language does
 * not. Without the intl extension, or for a code it does not know, the code alone is used.
 */
final class LanguageName
{
    public static function of(string $locale): string
    {
        $name = \function_exists('locale_get_display_language') ? locale_get_display_language($locale, 'en') : false;
        $code = strtolower((string) preg_replace('/[-_].*$/', '', $locale));

        // intl answers with the code itself when it does not know the language
        if (!\is_string($name) || strtolower($name) === $code) {
            return \sprintf('the language of the locale "%s"', $locale);
        }

        return \sprintf('%s (locale "%s")', $name, $locale);
    }
}
