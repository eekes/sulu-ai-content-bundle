<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema;

/**
 * Keeps the HTML a rich text field can hold and drops everything else: unknown elements are
 * unwrapped (their text stays), attributes outside the allow list are removed, and links can only
 * point to http(s), mailto, tel or a Sulu page.
 *
 * Generated HTML lands in an editor's form before anyone saves it, but it is still untrusted.
 */
final class HtmlCleaner
{
    /**
     * @var array<string, list<string>> element => allowed attributes
     */
    private const array ALLOWED = [
        'p' => [],
        'br' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'a' => ['href', 'target', 'rel', 'title'],
        'sulu-link' => ['href', 'provider', 'target', 'title'],
    ];

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body><div id="eekes-root">'.$html.'</div></body>', \LIBXML_HTML_NODEFDTD | \LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('eekes-root');

        if ($root === null) {
            return strip_tags($html);
        }

        $this->cleanChildren($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    private function cleanChildren(\DOMNode $node): void
    {
        // a live list shrinks and grows while unwrapping, so iterate over a copy
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                continue;
            }

            if (!$child instanceof \DOMElement) {
                $node->removeChild($child);

                continue;
            }

            $name = strtolower($child->nodeName);

            if (\in_array($name, ['script', 'style', 'iframe', 'object', 'embed', 'template'], true)) {
                $node->removeChild($child);

                continue;
            }

            $this->cleanChildren($child);

            if (!\array_key_exists($name, self::ALLOWED) || ($name === 'sulu-link' && !self::isPageLink($child))) {
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                if (!\in_array(strtolower($attribute->name), self::ALLOWED[$name], true) || !$this->isSafeAttribute($name, $attribute)) {
                    $child->removeAttribute($attribute->name);
                }
            }
        }
    }

    /**
     * Only links to pages: Sulu's other link providers - "external" above all, which prints its
     * href as it is - would let a reply put any URL on the website.
     */
    private static function isPageLink(\DOMElement $element): bool
    {
        $provider = strtolower(trim($element->getAttribute('provider')));

        return $provider === '' || $provider === 'page';
    }

    private function isSafeAttribute(string $element, \DOMAttr $attribute): bool
    {
        if (strtolower($attribute->name) !== 'href' || $element === 'sulu-link') {
            return true;
        }

        return preg_match('#^(https?://|mailto:|tel:|/|\#)#i', trim($attribute->value)) === 1;
    }
}
