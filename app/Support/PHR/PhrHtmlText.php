<?php

namespace App\Support\PHR;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The visible text of an HTML document, for a reader that must never be handed
 * markup: a model reading a note treats tags, scripts, comments and hidden
 * elements as a prompt-injection surface. Parsed into a DOM (never matched
 * with patterns), so malformed or nested tag tricks end up as elements or text,
 * and walked iteratively, so deep nesting cannot exhaust the stack.
 */
final class PhrHtmlText
{
    /** Elements whose content is never visible text. */
    private const array SKIPPED = [
        'head', 'script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed',
        'svg', 'math', 'canvas', 'audio', 'video', 'select', 'option', 'datalist', 'button',
    ];

    /** Elements followed by an empty line. */
    private const array PARAGRAPHS = ['blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ol', 'p', 'pre', 'table', 'ul'];

    /** Elements that start and end on their own line. */
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'body', 'caption', 'dd', 'details', 'dialog', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hr', 'html', 'legend', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody',
        'tfoot', 'thead', 'tr', 'ul',
    ];

    public static function toText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        // Read as UTF-8; a legacy single-byte page is taken as Windows-1252.
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = (string) mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded === false) {
            return '';
        }

        $out = '';
        /** @var list<array{0: DOMNode, 1: bool}> $stack node, and whether its children were already pushed */
        $stack = [[$document, false]];
        $preDepth = 0;
        while ($stack !== []) {
            [$node, $visited] = array_pop($stack);
            if ($node instanceof DOMText) {
                $text = (string) $node->data;
                if ($preDepth === 0) {
                    $text = (string) preg_replace('/\s+/u', ' ', $text);
                    if ($out === '' || str_ends_with($out, "\n") || str_ends_with($out, ' ')) {
                        $text = ltrim($text, ' ');
                    }
                }
                $out .= $text;

                continue;
            }
            $name = $node instanceof DOMElement ? strtolower((string) $node->localName) : null;
            if ($visited) {
                // Closing an element.
                if ($name === 'pre') {
                    $preDepth--;
                }
                if (in_array($name, ['td', 'th'], true)) {
                    $out .= ' | ';
                } elseif ($name !== null && in_array($name, self::PARAGRAPHS, true)) {
                    $out = self::endLine(self::endLine($out), blank: true);
                } elseif ($name !== null && in_array($name, self::BLOCKS, true)) {
                    $out = self::endLine($out);
                }

                continue;
            }
            if ($node instanceof DOMElement) {
                if (in_array($name, self::SKIPPED, true) || self::hidden($node)) {
                    continue;
                }
                if ($name === 'br') {
                    $out .= "\n";

                    continue;
                }
                if ($name === 'pre') {
                    $preDepth++;
                }
                if ($name !== null && in_array($name, self::BLOCKS, true)) {
                    $out = self::endLine($out);
                }
                if ($name === 'li') {
                    $out .= '- ';
                }
            } elseif (! $node instanceof DOMDocument) {
                // Comments, processing instructions and doctypes carry no visible text.
                continue;
            }
            $stack[] = [$node, true];
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            for ($index = count($children) - 1; $index >= 0; $index--) {
                $stack[] = [$children[$index], false];
            }
        }

        return self::tidy($out);
    }

    /** Ends the current line (or, with blank, leaves one empty line) unless the text already does. */
    private static function endLine(string $out, bool $blank = false): string
    {
        if ($out === '') {
            return $out;
        }
        $out = rtrim($out, ' ');
        if (! str_ends_with($out, "\n")) {
            $out .= "\n";
        }

        return $blank && ! str_ends_with($out, "\n\n") ? $out."\n" : $out;
    }

    /** Whether a string reads as HTML markup rather than text that mentions a bracket. */
    public static function looksLikeMarkup(string $text): bool
    {
        return preg_match('/<\s*\/?\s*[a-z!][^>]*>/i', $text) === 1;
    }

    private static function hidden(DOMElement $element): bool
    {
        if ($element->hasAttribute('hidden') || strtolower((string) $element->getAttribute('aria-hidden')) === 'true') {
            return true;
        }
        $style = strtolower((string) $element->getAttribute('style'));

        return $style !== '' && preg_match('/(display\s*:\s*none|visibility\s*:\s*hidden)/', $style) === 1;
    }

    private static function tidy(string $text): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            // A row's last cell separator, and trailing blanks, carry nothing.
            $lines[] = rtrim((string) preg_replace('/\s*\|\s*$/u', '', rtrim($line)));
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)), "\n");
    }
}
