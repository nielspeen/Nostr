<?php

namespace Modules\Nostr\Services;

/**
 * Converts the HTML written in the FreeScout editor to the plain text a Nostr
 * client shows. Keeps line breaks and lists, puts link targets in brackets.
 */
class HtmlToText
{
    public static function convert($html)
    {
        $text = (string) $html;
        if (trim($text) === '') {
            return '';
        }

        $text = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $text);
        $text = preg_replace('#<!--.*?-->#s', '', $text);

        // Links: "text (url)" unless the text already is the url.
        $text = preg_replace_callback('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($label === '' || $label === $url || rtrim($label, '/') === rtrim($url, '/')) {
                return $url;
            }
            if (stripos($url, 'mailto:') === 0 && $label === substr($url, 7)) {
                return $label;
            }

            return $label.' ('.$url.')';
        }, $text);

        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = preg_replace('#<li\b[^>]*>#i', "\n- ", $text);
        $text = preg_replace('#<(p|div|tr|h[1-6]|blockquote|pre|table|ul|ol|section|article|header|footer)\b[^>]*>#i', "\n", $text);
        $text = preg_replace('#</(p|div|tr|h[1-6]|blockquote|pre|table|ul|ol|section|article|header|footer)>#i', "\n", $text);
        $text = preg_replace('#</li>#i', '', $text);
        $text = preg_replace('#</t[dh]>#i', "\t", $text);

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xC2\xA0", "\r"], [' ', ''], $text);

        $lines = array_map(function ($line) {
            return rtrim($line);
        }, explode("\n", $text));
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
