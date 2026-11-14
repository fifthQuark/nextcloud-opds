<?php

declare(strict_types=1);

namespace OCA\Opds\Service;

use OCA\Opds\Db\Book;
use OCP\IURLGenerator;

/**
 * Serialises navigation and acquisition feeds as OPDS 1.2 (Atom) XML.
 */
class FeedBuilder {
    public const NAVIGATION = 'navigation';
    public const ACQUISITION = 'acquisition';

    public const TYPE_NAV = 'application/atom+xml;profile=opds-catalog;kind=navigation';
    public const TYPE_ACQ = 'application/atom+xml;profile=opds-catalog;kind=acquisition';

    private const NS_ATOM = 'http://www.w3.org/2005/Atom';
    private const NS_DC = 'http://purl.org/dc/terms/';
    private const NS_OPDS = 'http://opds-spec.org/2010/catalog';
    private const NS_OPENSEARCH = 'http://a9.com/-/spec/opensearch/1.1/';

    private const MIME_BY_FORMAT = [
        'epub' => 'application/epub+zip',
        'kepub' => 'application/epub+zip',
        'pdf' => 'application/pdf',
        'mobi' => 'application/x-mobipocket-ebook',
        'prc' => 'application/x-mobipocket-ebook',
        'azw' => 'application/vnd.amazon.ebook',
        'azw3' => 'application/x-mobi8-ebook',
        'azw4' => 'application/x-mobi8-ebook',
        'cbz' => 'application/x-cbz',
        'cbr' => 'application/x-cbr',
        'cb7' => 'application/x-cb7',
        'cbt' => 'application/x-cbt',
        'fb2' => 'application/fb2+xml',
        'djvu' => 'image/vnd.djvu',
        'djv' => 'image/vnd.djvu',
        'txt' => 'text/plain',
        'txtz' => 'application/x-txtz',
        'rtf' => 'application/rtf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'html' => 'text/html',
        'htm' => 'text/html',
        'lit' => 'application/x-ms-reader',
        'lrf' => 'application/x-sony-bbeb',
        'pdb' => 'application/vnd.palm',
        'chm' => 'application/vnd.ms-htmlhelp',
    ];

    public function __construct(
        private IURLGenerator $urlGenerator,
    ) {
    }

    public static function mimeForFormat(string $format, string $fallback): string {
        return self::MIME_BY_FORMAT[$format] ?? ($fallback !== '' ? $fallback : 'application/octet-stream');
    }

    public function route(string $name, array $params = []): string {
        return $this->urlGenerator->linkToRouteAbsolute('opds.opds.' . $name, $params);
    }

    /**
     * @param array{
     *   id: string, title: string, kind: string, updated: int, self: string, start: string,
     *   up?: ?string, subtitle?: string, search?: string, opensearch?: string,
     *   next?: ?string, previous?: ?string, first?: ?string, last?: ?string,
     *   total?: ?int, perPage?: ?int, startIndex?: ?int,
     *   facets?: list<array{group: string, title: string, href: string, active: bool, count?: int}>,
     *   entries: list<array<string,mixed>>
     * } $feed
     */
    public function render(array $feed): string {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElementNs(null, 'feed', self::NS_ATOM);
        $w->writeAttribute('xmlns:dc', self::NS_DC);
        $w->writeAttribute('xmlns:opds', self::NS_OPDS);
        $w->writeAttribute('xmlns:opensearch', self::NS_OPENSEARCH);

        $w->writeElement('id', $feed['id']);
        $w->writeElement('title', $feed['title']);
        if (!empty($feed['subtitle'])) {
            $w->writeElement('subtitle', $feed['subtitle']);
        }
        $w->writeElement('updated', $this->date($feed['updated']));
        $w->startElement('author');
        $w->writeElement('name', 'Nextcloud OPDS Catalog');
        $w->endElement();

        $kindType = $feed['kind'] === self::NAVIGATION ? self::TYPE_NAV : self::TYPE_ACQ;
        $this->link($w, 'self', $feed['self'], $kindType);
        $this->link($w, 'start', $feed['start'], self::TYPE_NAV, 'Catalog');
        if (!empty($feed['up'])) {
            $this->link($w, 'up', $feed['up'], self::TYPE_NAV);
        }
        if (!empty($feed['search'])) {
            $this->link($w, 'search', $feed['search'], self::TYPE_ACQ, 'Search');
        }
        if (!empty($feed['opensearch'])) {
            $this->link($w, 'search', $feed['opensearch'], 'application/opensearchdescription+xml', 'Search');
        }
        foreach (['first', 'previous', 'next', 'last'] as $rel) {
            if (!empty($feed[$rel])) {
                $this->link($w, $rel, $feed[$rel], $kindType);
            }
        }
        if (isset($feed['total'])) {
            $w->writeElementNs('opensearch', 'totalResults', null, (string)$feed['total']);
            $w->writeElementNs('opensearch', 'itemsPerPage', null, (string)($feed['perPage'] ?? count($feed['entries'])));
            $w->writeElementNs('opensearch', 'startIndex', null, (string)($feed['startIndex'] ?? 1));
        }
        foreach ($feed['facets'] ?? [] as $facet) {
            $w->startElement('link');
            $w->writeAttribute('rel', 'http://opds-spec.org/facet');
            $w->writeAttribute('href', $facet['href']);
            $w->writeAttribute('type', self::TYPE_ACQ);
            $w->writeAttribute('title', $facet['title']);
            $w->writeAttributeNs('opds', 'facetGroup', null, $facet['group']);
            if ($facet['active']) {
                $w->writeAttributeNs('opds', 'activeFacet', null, 'true');
            }
            if (isset($facet['count'])) {
                $w->writeAttributeNs('thr', 'count', 'http://purl.org/syndication/thread/1.0', (string)$facet['count']);
            }
            $w->endElement();
        }

        foreach ($feed['entries'] as $entry) {
            if (($entry['type'] ?? '') === 'book') {
                $this->bookEntry($w, $entry['book'], $entry);
            } else {
                $this->navEntry($w, $entry);
            }
        }

        $w->endElement();
        $w->endDocument();
        return $w->outputMemory();
    }

    public function renderOpenSearch(string $template, string $selfUrl): string {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElementNs(null, 'OpenSearchDescription', 'http://a9.com/-/spec/opensearch/1.1/');
        $w->writeElement('ShortName', 'Nextcloud OPDS');
        $w->writeElement('Description', 'Search the Nextcloud OPDS catalog');
        $w->writeElement('InputEncoding', 'UTF-8');
        $w->writeElement('OutputEncoding', 'UTF-8');
        $w->startElement('Url');
        $w->writeAttribute('type', self::TYPE_ACQ);
        $w->writeAttribute('template', $template);
        $w->endElement();
        $w->startElement('Url');
        $w->writeAttribute('type', 'application/opensearchdescription+xml');
        $w->writeAttribute('rel', 'self');
        $w->writeAttribute('template', $selfUrl);
        $w->endElement();
        $w->endElement();
        $w->endDocument();
        return $w->outputMemory();
    }

    /** @param array<string,mixed> $entry */
    private function navEntry(\XMLWriter $w, array $entry): void {
        $w->startElement('entry');
        $w->writeElement('title', (string)$entry['title']);
        $w->writeElement('id', (string)$entry['id']);
        $w->writeElement('updated', $this->date((int)($entry['updated'] ?? time())));
        if (!empty($entry['summary'])) {
            $w->startElement('content');
            $w->writeAttribute('type', 'text');
            $w->text((string)$entry['summary']);
            $w->endElement();
        }
        $type = ($entry['linkKind'] ?? self::ACQUISITION) === self::NAVIGATION ? self::TYPE_NAV : self::TYPE_ACQ;
        $this->link($w, $entry['rel'] ?? 'subsection', (string)$entry['href'], $type);
        if (!empty($entry['thumbnail'])) {
            $this->link($w, 'http://opds-spec.org/image/thumbnail', (string)$entry['thumbnail'], (string)($entry['thumbnailType'] ?? 'image/jpeg'));
        }
        $w->endElement();
    }

    /** @param array<string,mixed> $entry */
    private function bookEntry(\XMLWriter $w, Book $book, array $entry): void {
        $w->startElement('entry');
        $w->writeElement('title', $book->title);
        $w->writeElement('id', 'urn:nextcloud-opds:book:' . $book->fileId);
        $w->writeElement('updated', $this->date($book->mtime));
        foreach ($book->authors as $author) {
            $w->startElement('author');
            $w->writeElement('name', $author);
            if (!empty($entry['authorUrl'])) {
                $w->writeElement('uri', $entry['authorUrl']($author));
            }
            $w->endElement();
        }
        if ($book->language !== '') {
            $w->writeElementNs('dc', 'language', null, $book->language);
        }
        if ($book->publisher !== '') {
            $w->writeElementNs('dc', 'publisher', null, $book->publisher);
        }
        if ($book->pubdate !== '') {
            $w->writeElementNs('dc', 'issued', null, $book->pubdate);
            $w->writeElement('published', $this->isoDate($book->pubdate));
        }
        if ($book->isbn !== '') {
            $w->writeElementNs('dc', 'identifier', null, 'urn:isbn:' . $book->isbn);
        }
        $w->writeElementNs('dc', 'format', null, strtoupper($book->format));
        if ($book->series !== '') {
            $w->writeElementNs('dc', 'isPartOf', null, $book->series);
        }
        foreach ($book->subjects as $subject) {
            $w->startElement('category');
            $w->writeAttribute('term', $subject);
            $w->writeAttribute('label', $subject);
            $w->endElement();
        }

        $summaryParts = [];
        if ($book->series !== '') {
            $summaryParts[] = 'Series: ' . $book->series . ($book->seriesIndex > 0 ? ' #' . $this->number($book->seriesIndex) : '');
        }
        if ($book->description !== '') {
            $summaryParts[] = $book->description;
        }
        $details = array_filter([
            strtoupper($book->format) . ', ' . $this->size($book->size),
            $book->publisher !== '' ? 'Publisher: ' . $book->publisher : '',
            $book->pubdate !== '' ? 'Published: ' . $book->pubdate : '',
            $book->language !== '' ? 'Language: ' . $book->language : '',
        ]);
        $summaryParts[] = implode(' · ', $details);
        $w->startElement('summary');
        $w->writeAttribute('type', 'text');
        $w->text(implode("\n\n", $summaryParts));
        $w->endElement();

        $mime = self::mimeForFormat($book->format, $book->mimetype);
        $this->link($w, 'http://opds-spec.org/acquisition', (string)$entry['download'], $mime, 'Download ' . strtoupper($book->format) . ' (' . $this->size($book->size) . ')');
        if ($book->hasCover && !empty($entry['cover'])) {
            $this->link($w, 'http://opds-spec.org/image', (string)$entry['cover'], 'image/jpeg');
            $this->link($w, 'http://opds-spec.org/image/thumbnail', (string)$entry['thumbnail'], 'image/jpeg');
        }
        foreach ($entry['related'] ?? [] as $related) {
            $this->link($w, 'related', $related['href'], self::TYPE_ACQ, $related['title']);
        }
        $w->endElement();
    }

    private function link(\XMLWriter $w, string $rel, string $href, string $type, ?string $title = null): void {
        $w->startElement('link');
        $w->writeAttribute('rel', $rel);
        $w->writeAttribute('href', $href);
        $w->writeAttribute('type', $type);
        if ($title !== null && $title !== '') {
            $w->writeAttribute('title', $title);
        }
        $w->endElement();
    }

    private function date(int $timestamp): string {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp > 0 ? $timestamp : time());
    }

    private function isoDate(string $pubdate): string {
        $parts = explode('-', $pubdate) + ['1970', '01', '01'];
        return sprintf('%04d-%02d-%02dT00:00:00Z', (int)$parts[0], max(1, (int)$parts[1]), max(1, (int)$parts[2]));
    }

    private function number(float $n): string {
        return floor($n) == $n ? (string)(int)$n : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    public function size(int $bytes): string {
        if ($bytes >= 1073741824) {
            return sprintf('%.1f GB', $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.0f KB', $bytes / 1024);
        }
        return $bytes . ' B';
    }
}
