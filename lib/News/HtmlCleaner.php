<?php

declare(strict_types=1);

namespace OCA\Opds\News;

/**
 * Turns the (already purified) HTML of a feed article into a well formed
 * XHTML fragment that is safe to put into an EPUB: unknown markup is unwrapped,
 * active and embedded content is dropped, links are made absolute and images
 * are either replaced by a local copy or removed.
 */
class HtmlCleaner {
    /** Elements removed together with everything inside them. */
    private const DROP = [
        'script', 'style', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
        'button', 'select', 'textarea', 'option', 'optgroup', 'svg', 'math', 'video', 'audio', 'source', 'track',
        'canvas', 'template', 'link', 'meta', 'head', 'title', 'base', 'map', 'area', 'dialog', 'portal',
    ];

    /** Elements kept as they are (attributes are filtered). */
    private const KEEP = [
        'p', 'br', 'hr', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'u', 's', 'del', 'ins', 'sub', 'sup', 'small',
        'mark', 'code', 'pre', 'kbd', 'samp', 'var', 'blockquote', 'q', 'cite', 'abbr', 'dfn', 'ul', 'ol', 'li', 'dl',
        'dt', 'dd', 'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'figure', 'figcaption', 'div',
        'span', 'section', 'article', 'aside', 'header', 'footer', 'time', 'a', 'img', 'address',
    ];

    private const RENAME = [
        'h1' => 'h2', 'b' => 'strong', 'i' => 'em', 'strike' => 's', 'tt' => 'code', 'center' => 'div', 'big' => 'strong',
    ];

    private const VOID = ['br', 'hr', 'img'];

    /** @var callable(string):?string|null */
    private $imageResolver = null;
    private string $baseUrl = '';
    private \DOMDocument $out;

    /**
     * @param callable(string):?string|null $imageResolver gets an absolute image URL (or data: URI) and
     *        returns the href of the local copy inside the EPUB, or null to drop the image
     */
    public function clean(string $html, string $baseUrl, ?callable $imageResolver): string {
        $this->baseUrl = $baseUrl;
        $this->imageResolver = $imageResolver;
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (!preg_match('#<(/?[a-z][a-z0-9-]*|!--)[\s/>]#i', $html)) {
            // Plain text body
            $paragraphs = preg_split('/\R{2,}/', $html) ?: [$html];
            $html = '<p>' . implode('</p><p>', array_map(static fn (string $p): string => nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')), $paragraphs)) . '</p>';
        }

        $in = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $in->loadHTML(
            '<?xml encoding="UTF-8"?><!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $in->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        $this->out = new \DOMDocument('1.0', 'UTF-8');
        $root = $this->out->createElement('div');
        $this->out->appendChild($root);
        $this->copyChildren($body, $root, 0, false);
        $this->pruneEmpty($root);

        $xml = '';
        foreach ($root->childNodes as $child) {
            $xml .= $this->out->saveXML($child);
        }
        return $xml;
    }

    private function copyChildren(\DOMNode $src, \DOMNode $dst, int $depth, bool $inPre): void {
        foreach (iterator_to_array($src->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                $text = self::xmlText($child->nodeValue ?? '');
                // Some feeds escape their HTML twice, leaving "&#34;" or "&amp;" as visible text
                if (str_contains($text, '&') && preg_match('/&(#[0-9]{1,7}|#x[0-9a-f]{1,6}|[a-z][a-z0-9]{1,31});/i', $text)) {
                    $text = self::xmlText(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                if (!$inPre) {
                    $text = preg_replace('/[ \t\r\n]+/', ' ', $text) ?? $text;
                }
                if ($text !== '') {
                    $dst->appendChild($this->out->createTextNode($text));
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue; // comments, processing instructions, CDATA from broken markup
            }
            $tag = strtolower($child->localName ?? $child->nodeName);
            if (in_array($tag, self::DROP, true)) {
                continue;
            }
            if ($depth > 64) {
                $dst->appendChild($this->out->createTextNode(self::xmlText($child->textContent)));
                continue;
            }
            if ($tag === 'img') {
                $this->image($child, $dst);
                continue;
            }
            $tag = self::RENAME[$tag] ?? $tag;
            if (!in_array($tag, self::KEEP, true)) {
                $this->copyChildren($child, $dst, $depth + 1, $inPre);
                continue;
            }
            if ($tag === 'a') {
                $href = $this->absoluteUrl(trim($child->getAttribute('href')));
                if ($href === null || !preg_match('#^(https?:|mailto:)#i', $href)) {
                    $this->copyChildren($child, $dst, $depth + 1, $inPre);
                    continue;
                }
            }
            $el = $this->out->createElement($tag);
            $this->copyAttributes($child, $el, $tag);
            $dst->appendChild($el);
            if (!in_array($tag, self::VOID, true)) {
                $this->copyChildren($child, $el, $depth + 1, $inPre || $tag === 'pre');
            }
        }
    }

    private function copyAttributes(\DOMElement $from, \DOMElement $to, string $tag): void {
        foreach (['title', 'lang', 'dir'] as $name) {
            $value = trim($from->getAttribute($name));
            if ($value === '') {
                continue;
            }
            if ($name === 'dir' && !in_array(strtolower($value), ['ltr', 'rtl', 'auto'], true)) {
                continue;
            }
            if ($name === 'lang' && !preg_match('/^[A-Za-z]{1,8}(-[A-Za-z0-9]{1,8})*$/', $value)) {
                continue;
            }
            $to->setAttribute($name, self::xmlText($value));
        }
        if ($tag === 'a') {
            $to->setAttribute('href', (string)$this->absoluteUrl(trim($from->getAttribute('href'))));
        }
        if ($tag === 'td' || $tag === 'th') {
            foreach (['colspan', 'rowspan'] as $name) {
                $n = (int)$from->getAttribute($name);
                if ($n > 1 && $n < 1000) {
                    $to->setAttribute($name, (string)$n);
                }
            }
        }
        if ($tag === 'ol') {
            $start = $from->getAttribute('start');
            if ($start !== '' && is_numeric($start)) {
                $to->setAttribute('start', (string)(int)$start);
            }
        }
        if ($tag === 'time') {
            $value = trim($from->getAttribute('datetime'));
            if ($value !== '' && preg_match('/^[0-9T:+\-.Z ]+$/', $value)) {
                $to->setAttribute('datetime', $value);
            }
        }
    }

    private function image(\DOMElement $img, \DOMNode $dst): void {
        if ($this->imageResolver === null) {
            return;
        }
        // Tracking pixels
        if ($img->getAttribute('width') === '1' || $img->getAttribute('height') === '1') {
            return;
        }
        $src = '';
        foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attribute) {
            $candidate = trim($img->getAttribute($attribute));
            if ($candidate !== '' && !str_starts_with($candidate, 'data:image/gif;base64,R0lGOD')) {
                $src = $candidate;
                break;
            }
        }
        if ($src === '') {
            $srcset = trim($img->getAttribute('srcset') ?: $img->getAttribute('data-srcset'));
            if ($srcset !== '') {
                $src = (string)preg_split('/\s+/', $srcset)[0];
            }
        }
        $url = str_starts_with($src, 'data:') ? $src : $this->absoluteUrl($src);
        if ($url === null || $url === '') {
            return;
        }
        $local = ($this->imageResolver)($url);
        if ($local === null) {
            return;
        }
        $el = $this->out->createElement('img');
        $el->setAttribute('src', $local);
        $el->setAttribute('alt', self::xmlText(trim($img->getAttribute('alt'))));
        $dst->appendChild($el);
    }

    /** Removes elements that ended up without any text or image (e.g. a wrapper around a dropped iframe). */
    private function pruneEmpty(\DOMNode $node): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $this->pruneEmpty($child);
            $tag = $child->tagName;
            if (in_array($tag, ['br', 'hr', 'img', 'td', 'th'], true)) {
                continue;
            }
            if (trim($child->textContent, " \t\n\r\0\x0B\u{00A0}") === '' && $child->getElementsByTagName('img')->length === 0) {
                $node->removeChild($child);
            }
        }
    }

    public function absoluteUrl(string $url): ?string {
        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            return $url;
        }
        $base = parse_url($this->baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($url, '//')) {
            return $base['scheme'] . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        $path = $base['path'] ?? '/';
        $dir = substr($path, 0, (int)strrpos($path, '/') + 1);
        $full = $dir . $url;
        // Resolve ./ and ../ segments
        $segments = [];
        foreach (explode('/', $full) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        return $origin . '/' . ltrim(implode('/', $segments), '/');
    }

    /** Valid UTF-8 without characters XML 1.0 forbids. */
    public static function xmlText(string $text): string {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
    }
}
