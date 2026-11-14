<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

/**
 * Reads the OPF package document of an EPUB 2/3 file.
 */
class EpubExtractor {
    private const OPF_NS = 'http://www.idpf.org/2007/opf';

    public function extract(string $localPath): ?Metadata {
        $zip = new \ZipArchive();
        if ($zip->open($localPath) !== true) {
            return null;
        }
        try {
            $opfPath = $this->findOpfPath($zip);
            if ($opfPath === null) {
                return null;
            }
            $opfXml = $zip->getFromName($opfPath);
            if ($opfXml === false) {
                return null;
            }
            $doc = Text::dom($opfXml);
            if ($doc === null) {
                return null;
            }
            $meta = $this->parseOpf($doc);
            $coverHref = $this->findCoverHref($doc);
            if ($coverHref !== null) {
                $meta->setCover($this->readZipEntry($zip, $this->resolve($opfPath, $coverHref)));
            }
            return $meta;
        } finally {
            $zip->close();
        }
    }

    private function findOpfPath(\ZipArchive $zip): ?string {
        $container = $zip->getFromName('META-INF/container.xml');
        if ($container !== false) {
            $doc = Text::dom($container);
            if ($doc !== null) {
                $xp = new \DOMXPath($doc);
                $nodes = $xp->query('//' . Text::ln('rootfile'));
                if ($nodes !== false) {
                    foreach ($nodes as $node) {
                        /** @var \DOMElement $node */
                        $path = $node->getAttribute('full-path');
                        $type = $node->getAttribute('media-type');
                        if ($path !== '' && ($type === '' || str_contains($type, 'oebps-package'))) {
                            return ltrim($path, '/');
                        }
                    }
                }
            }
            if (preg_match('/full-path\s*=\s*["\']([^"\']+)["\']/', $container, $m)) {
                return ltrim($m[1], '/');
            }
        }
        // Broken container: look for any .opf file
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && str_ends_with(strtolower($name), '.opf')) {
                return $name;
            }
        }
        return null;
    }

    private function parseOpf(\DOMDocument $doc): Metadata {
        $meta = new Metadata();
        $xp = new \DOMXPath($doc);
        $md = '//' . Text::ln('metadata') . '/';

        // EPUB 3 "refines" meta elements: id => [property => value]
        $refines = [];
        $metaNodes = $xp->query($md . Text::ln('meta'));
        if ($metaNodes !== false) {
            foreach ($metaNodes as $node) {
                /** @var \DOMElement $node */
                $target = ltrim($node->getAttribute('refines'), '#');
                $property = $node->getAttribute('property');
                if ($target !== '' && $property !== '') {
                    $refines[$target][$property] = Text::clean($node->textContent);
                }
            }
        }

        // Title: prefer the main title when several are given
        $titles = $xp->query($md . Text::ln('title'));
        if ($titles !== false && $titles->length > 0) {
            $chosen = null;
            foreach ($titles as $node) {
                /** @var \DOMElement $node */
                $id = $node->getAttribute('id');
                $type = $refines[$id]['title-type'] ?? '';
                if ($type === 'main') {
                    $chosen = $node;
                    break;
                }
            }
            $chosen ??= $titles->item(0);
            $meta->title = Text::clean($chosen->textContent ?? '');
        }

        // Creators (authors)
        $creators = $xp->query($md . Text::ln('creator'));
        $fallback = [];
        if ($creators !== false) {
            foreach ($creators as $node) {
                /** @var \DOMElement $node */
                $name = Text::clean($node->textContent);
                if ($name === '') {
                    continue;
                }
                $id = $node->getAttribute('id');
                $role = strtolower($this->opfAttr($node, 'role') ?: ($refines[$id]['role'] ?? ''));
                $fileAs = $this->opfAttr($node, 'file-as') ?: ($refines[$id]['file-as'] ?? '');
                $fallback[] = [$name, $fileAs];
                if ($role === '' || $role === 'aut') {
                    $meta->addAuthor($name, $fileAs);
                }
            }
        }
        if ($meta->authors === []) {
            foreach ($fallback as [$name, $fileAs]) {
                $meta->addAuthor($name, $fileAs);
            }
        }

        $meta->publisher = Text::firstText($xp, $md . Text::ln('publisher'));
        $meta->description = Text::plain(Text::firstText($xp, $md . Text::ln('description')));
        $meta->language = Text::language(Text::firstText($xp, $md . Text::ln('language')));
        foreach (Text::allText($xp, $md . Text::ln('subject')) as $subject) {
            $meta->addSubject($subject);
        }

        // Dates: prefer a publication event
        $dates = $xp->query($md . Text::ln('date'));
        if ($dates !== false && $dates->length > 0) {
            $best = '';
            foreach ($dates as $node) {
                /** @var \DOMElement $node */
                $event = strtolower($this->opfAttr($node, 'event'));
                $value = Text::clean($node->textContent);
                if ($best === '' || in_array($event, ['publication', 'original-publication', 'ops-publication'], true)) {
                    $best = $value;
                    if ($event !== '') {
                        break;
                    }
                }
            }
            $meta->date = $best;
        }

        // Identifiers
        $ids = $xp->query($md . Text::ln('identifier'));
        if ($ids !== false) {
            foreach ($ids as $node) {
                /** @var \DOMElement $node */
                $scheme = strtolower($this->opfAttr($node, 'scheme'));
                $value = Text::clean($node->textContent);
                if ($scheme === 'isbn' || stripos($value, 'isbn') !== false) {
                    $isbn = Text::isbn($value);
                    if ($isbn !== '') {
                        $meta->isbn = $isbn;
                        break;
                    }
                }
            }
            if ($meta->isbn === '') {
                foreach ($ids as $node) {
                    $isbn = Text::isbn(Text::clean($node->textContent));
                    if ($isbn !== '' && strlen($isbn) === 13) {
                        $meta->isbn = $isbn;
                        break;
                    }
                }
            }
        }

        // Series: Calibre (EPUB 2) or belongs-to-collection (EPUB 3)
        if ($metaNodes !== false) {
            foreach ($metaNodes as $node) {
                /** @var \DOMElement $node */
                $name = $node->getAttribute('name');
                if ($name === 'calibre:series') {
                    $meta->series = Text::clean($node->getAttribute('content'));
                } elseif ($name === 'calibre:series_index') {
                    $meta->seriesIndex = (float)$node->getAttribute('content');
                }
            }
            if ($meta->series === '') {
                foreach ($metaNodes as $node) {
                    /** @var \DOMElement $node */
                    if ($node->getAttribute('property') !== 'belongs-to-collection') {
                        continue;
                    }
                    $id = $node->getAttribute('id');
                    $type = $refines[$id]['collection-type'] ?? 'series';
                    if ($type !== 'series') {
                        continue;
                    }
                    $meta->series = Text::clean($node->textContent);
                    $meta->seriesIndex = (float)($refines[$id]['group-position'] ?? 0);
                    break;
                }
            }
        }

        return $meta;
    }

    private function findCoverHref(\DOMDocument $doc): ?string {
        $xp = new \DOMXPath($doc);
        $items = $xp->query('//' . Text::ln('manifest', 'item'));
        if ($items === false || $items->length === 0) {
            return null;
        }
        $byId = [];
        $images = [];
        foreach ($items as $item) {
            /** @var \DOMElement $item */
            $id = $item->getAttribute('id');
            $href = $item->getAttribute('href');
            $type = strtolower($item->getAttribute('media-type'));
            $props = ' ' . $item->getAttribute('properties') . ' ';
            if (str_contains($props, ' cover-image ')) {
                return $href;
            }
            if ($id !== '') {
                $byId[$id] = [$href, $type];
            }
            if (str_starts_with($type, 'image/') || Text::isImageName($href)) {
                $images[] = [$id, $href];
            }
        }

        $coverMeta = $xp->query('//' . Text::ln('metadata', 'meta') . "[@name='cover']");
        if ($coverMeta !== false && $coverMeta->length > 0) {
            /** @var \DOMElement $node */
            $node = $coverMeta->item(0);
            $content = $node->getAttribute('content');
            if (isset($byId[$content])) {
                [$href, $type] = $byId[$content];
                if (str_starts_with($type, 'image/') || Text::isImageName($href)) {
                    return $href;
                }
                // Some files point to the XHTML cover page; look for an image with a cover-ish name instead
            } elseif (Text::isImageName($content)) {
                return $content;
            }
        }

        foreach ($images as [$id, $href]) {
            if (preg_match('/cover/i', $id) || preg_match('/cover/i', basename($href))) {
                return $href;
            }
        }
        return null;
    }

    private function opfAttr(\DOMElement $el, string $name): string {
        $value = $el->getAttributeNS(self::OPF_NS, $name);
        if ($value === '') {
            $value = $el->getAttribute('opf:' . $name);
        }
        if ($value === '') {
            $value = $el->getAttribute($name);
        }
        return $value;
    }

    private function resolve(string $opfPath, string $href): string {
        $href = rawurldecode(explode('#', $href, 2)[0]);
        $dir = dirname($opfPath);
        $path = ($dir === '.' || $dir === '' || $dir === '/') ? $href : $dir . '/' . $href;
        // Normalise ../ segments
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
            } else {
                $parts[] = $segment;
            }
        }
        return implode('/', $parts);
    }

    private function readZipEntry(\ZipArchive $zip, string $name): ?string {
        $data = $zip->getFromName($name);
        if ($data === false) {
            // Case-insensitive fallback
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (is_string($entry) && strcasecmp($entry, $name) === 0) {
                    $data = $zip->getFromIndex($i);
                    break;
                }
            }
        }
        return $data === false ? null : $data;
    }
}
