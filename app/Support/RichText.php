<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Rich text from the job editor, made safe: only basic formatting tags survive, every attribute is
 * dropped except a link's http(s)/mailto/tel href, and scripts/styles are removed with their content.
 */
class RichText
{
    private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'a', 'span'];

    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'head', 'title'];

    public static function isHtml(?string $value): bool
    {
        return $value !== null && preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $value) === 1;
    }

    public static function clean(string $html): string
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root') ?? $doc->documentElement;
        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim(preg_replace('/(<p>(\s|&nbsp;|<br>)*<\/p>\s*)+$/i', '', $out));
    }

    /** Plain text for lists, SMS and the mobile apps: block tags become line breaks. */
    public static function toText(string $html): string
    {
        $text = preg_replace(['/<\s*br\s*\/?>/i', '/<\/(p|li|h[2-4]|blockquote)>/i', '/<li[^>]*>/i'], ["\n", "\n", '• '], $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace(["/[ \t]+\n/", "/\n{3,}/"], ["\n", "\n\n"], $text));
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }
            self::walk($child);
            if (! in_array($tag, self::ALLOWED, true)) {
                // Unknown tag: keep its (already cleaned) content, drop the tag.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }
            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
            foreach (iterator_to_array($child->attributes) as $attr) {
                $child->removeAttribute($attr->nodeName);
            }
            if ($tag === 'a') {
                if (preg_match('#^(https?://|mailto:|tel:)#i', $href)) {
                    $child->setAttribute('href', $href);
                    $child->setAttribute('rel', 'noopener noreferrer nofollow');
                    $child->setAttribute('target', '_blank');
                }
            }
        }
    }
}
