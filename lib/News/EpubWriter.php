<?php

declare(strict_types=1);

namespace OCA\Opds\News;

/**
 * Writes a news edition as an EPUB 3 book (with an EPUB 2 NCX table of
 * contents for older readers): a title page with the contents, then one part
 * per feed, each followed by one chapter per article.
 *
 * @psalm-type Article = array{title:string, author:string, url:string, pubDate:int, rtl:bool, html:string}
 * @psalm-type Part = array{title:string, folder:string, link:string, articles:list<Article>}
 * @psalm-type Book = array{
 *   id:string, title:string, subtitle:string, language:string, date:\DateTimeImmutable, timezone:\DateTimeZone, modified:int,
 *   parts:list<Part>, images:list<array{href:string, mime:string, data:string}>
 * }
 */
class EpubWriter {
    private const CSS = <<<'CSS'
body { margin: 0 0.4em; line-height: 1.45; }
h1 { font-size: 1.45em; line-height: 1.2; margin: 0.6em 0 0.3em; }
h2 { font-size: 1.2em; } h3, h4, h5, h6 { font-size: 1.05em; }
p { margin: 0 0 0.7em; }
img { max-width: 100%; height: auto; }
figure { margin: 1em 0; } figcaption, .meta, .source { font-size: 0.85em; color: #555; }
.meta { margin: 0 0 1.2em; }
.source { margin-top: 1.5em; border-top: 1px solid #ccc; padding-top: 0.4em; }
blockquote { margin: 0.8em 1.2em; font-style: italic; }
pre { white-space: pre-wrap; font-size: 0.85em; }
table { border-collapse: collapse; max-width: 100%; } td, th { border: 1px solid #bbb; padding: 0.15em 0.35em; }
.part { text-align: center; margin-top: 30%; }
.part h1 { font-size: 1.8em; }
.title-page h1 { font-size: 2.2em; text-align: center; margin-top: 15%; }
.title-page .when { text-align: center; }
.contents li { margin: 0.2em 0; }
CSS;

    private \DateTimeZone $timezone;

    /** @param Book $book */
    public function write(string $path, array $book): void {
        $this->timezone = $book['timezone'];
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create ' . $path);
        }
        // The mimetype entry must come first and be stored uncompressed
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/container.xml', $this->container());
        $zip->addFromString('OEBPS/style.css', self::CSS);

        $lang = $book['language'];
        $spine = [['id' => 'title', 'href' => 'title.xhtml']];
        $zip->addFromString('OEBPS/title.xhtml', $this->titlePage($book));

        foreach ($book['parts'] as $p => $part) {
            $partId = sprintf('p%03d', $p + 1);
            $zip->addFromString('OEBPS/text/' . $partId . '.xhtml', $this->partPage($part, $lang));
            $spine[] = ['id' => $partId, 'href' => 'text/' . $partId . '.xhtml'];
            foreach ($part['articles'] as $a => $article) {
                $articleId = sprintf('%s-a%03d', $partId, $a + 1);
                $zip->addFromString('OEBPS/text/' . $articleId . '.xhtml', $this->articlePage($article, $part, $lang));
                $spine[] = ['id' => $articleId, 'href' => 'text/' . $articleId . '.xhtml'];
            }
        }
        foreach ($book['images'] as $image) {
            // Already compressed, so storing them is faster and not larger
            $zip->addFromString('OEBPS/' . $image['href'], $image['data']);
            $zip->setCompressionName('OEBPS/' . $image['href'], \ZipArchive::CM_STORE);
        }
        $zip->addFromString('OEBPS/nav.xhtml', $this->nav($book));
        $zip->addFromString('OEBPS/toc.ncx', $this->ncx($book));
        $zip->addFromString('OEBPS/content.opf', $this->opf($book, $spine));
        if (!$zip->close()) {
            throw new \RuntimeException('Cannot write ' . $path);
        }
    }

    private function container(): string {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML;
    }

    /**
     * @param Book $book
     * @param list<array{id:string,href:string}> $spine
     */
    private function opf(array $book, array $spine): string {
        $e = self::e(...);
        $date = $book['date']->format('Y-m-d');
        $modified = gmdate('Y-m-d\TH:i:s\Z', $book['modified']);
        $feeds = implode(', ', array_map(static fn (array $p): string => $p['title'], $book['parts']));

        $manifest = [
            '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>',
            '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>',
            '<item id="css" href="style.css" media-type="text/css"/>',
        ];
        foreach ($spine as $item) {
            $manifest[] = sprintf('<item id="%s" href="%s" media-type="application/xhtml+xml"/>', $item['id'], $item['href']);
        }
        foreach ($book['images'] as $i => $image) {
            $manifest[] = sprintf('<item id="img%04d" href="%s" media-type="%s"/>', $i + 1, $e($image['href']), $e($image['mime']));
        }
        $itemrefs = array_map(static fn (array $item): string => sprintf('<itemref idref="%s"/>', $item['id']), $spine);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="' . $e($book['language']) . '">' . "\n"
            . '  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n"
            . '    <dc:identifier id="bookid">' . $e($book['id']) . '</dc:identifier>' . "\n"
            . '    <dc:title>' . $e($book['title']) . '</dc:title>' . "\n"
            . '    <dc:language>' . $e($book['language']) . '</dc:language>' . "\n"
            . '    <dc:creator>Nextcloud News</dc:creator>' . "\n"
            . '    <dc:publisher>OPDS+</dc:publisher>' . "\n"
            . '    <dc:date>' . $date . '</dc:date>' . "\n"
            . '    <dc:subject>News</dc:subject>' . "\n"
            . '    <dc:description>' . $e($book['subtitle'] . ($feeds !== '' ? '. ' . $feeds : '')) . '</dc:description>' . "\n"
            . '    <meta property="dcterms:modified">' . $modified . '</meta>' . "\n"
            . '    <meta property="belongs-to-collection" id="collection">News</meta>' . "\n"
            . '    <meta refines="#collection" property="collection-type">series</meta>' . "\n"
            . '    <meta name="calibre:series" content="News"/>' . "\n"
            . '  </metadata>' . "\n"
            . '  <manifest>' . "\n    " . implode("\n    ", $manifest) . "\n  </manifest>\n"
            . '  <spine toc="ncx">' . "\n    " . implode("\n    ", $itemrefs) . "\n  </spine>\n"
            . '</package>' . "\n";
    }

    /** @param Book $book */
    private function nav(array $book): string {
        $e = self::e(...);
        $items = '';
        foreach ($book['parts'] as $p => $part) {
            $partId = sprintf('p%03d', $p + 1);
            $items .= '<li><a href="text/' . $partId . '.xhtml">' . $e($part['title']) . '</a><ol>';
            foreach ($part['articles'] as $a => $article) {
                $items .= '<li><a href="text/' . sprintf('%s-a%03d', $partId, $a + 1) . '.xhtml">' . $e($this->articleTitle($article)) . '</a></li>';
            }
            $items .= '</ol></li>' . "\n";
        }
        $body = '<nav epub:type="toc" id="toc"><h1>Contents</h1><ol>' . "\n"
            . '<li><a href="title.xhtml">' . $e($book['title']) . '</a></li>' . "\n" . $items . '</ol></nav>' . "\n"
            . '<nav epub:type="landmarks" hidden="hidden"><ol>'
            . '<li><a epub:type="toc" href="title.xhtml">Contents</a></li>'
            . ($book['parts'] !== [] ? '<li><a epub:type="bodymatter" href="text/p001.xhtml">Start</a></li>' : '')
            . '</ol></nav>';
        return $this->page('Contents', $body, $book['language'], '');
    }

    /** @param Book $book */
    private function ncx(array $book): string {
        $e = self::e(...);
        $order = 1;
        $points = '<navPoint id="np-title" playOrder="' . $order++ . '"><navLabel><text>' . $e($book['title']) . '</text></navLabel><content src="title.xhtml"/></navPoint>' . "\n";
        foreach ($book['parts'] as $p => $part) {
            $partId = sprintf('p%03d', $p + 1);
            $points .= '<navPoint id="np-' . $partId . '" playOrder="' . $order++ . '"><navLabel><text>' . $e($part['title']) . '</text></navLabel><content src="text/' . $partId . '.xhtml"/>' . "\n";
            foreach ($part['articles'] as $a => $article) {
                $articleId = sprintf('%s-a%03d', $partId, $a + 1);
                $points .= '  <navPoint id="np-' . $articleId . '" playOrder="' . $order++ . '"><navLabel><text>' . $e($this->articleTitle($article)) . '</text></navLabel><content src="text/' . $articleId . '.xhtml"/></navPoint>' . "\n";
            }
            $points .= '</navPoint>' . "\n";
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1" xml:lang="' . $e($book['language']) . '">' . "\n"
            . '<head><meta name="dtb:uid" content="' . $e($book['id']) . '"/><meta name="dtb:depth" content="2"/>'
            . '<meta name="dtb:totalPageCount" content="0"/><meta name="dtb:maxPageNumber" content="0"/></head>' . "\n"
            . '<docTitle><text>' . $e($book['title']) . '</text></docTitle>' . "\n"
            . '<navMap>' . "\n" . $points . '</navMap>' . "\n" . '</ncx>' . "\n";
    }

    /** @param Book $book */
    private function titlePage(array $book): string {
        $e = self::e(...);
        $count = array_sum(array_map(static fn (array $p): int => count($p['articles']), $book['parts']));
        $body = '<section class="title-page" epub:type="titlepage">' . "\n"
            . '<h1>' . $e($book['title']) . '</h1>' . "\n"
            . '<p class="when">' . $e($book['subtitle']) . '</p>' . "\n"
            . '<p class="when">' . $count . ' ' . ($count === 1 ? 'article' : 'articles') . ' from ' . count($book['parts']) . ' ' . (count($book['parts']) === 1 ? 'feed' : 'feeds') . '</p>' . "\n"
            . '</section>' . "\n"
            . '<h2>Contents</h2>' . "\n" . '<ol class="contents">' . "\n";
        foreach ($book['parts'] as $p => $part) {
            $n = count($part['articles']);
            $body .= '<li><a href="text/' . sprintf('p%03d', $p + 1) . '.xhtml">' . $e($part['title']) . '</a> (' . $n . ')</li>' . "\n";
        }
        $body .= '</ol>';
        return $this->page($book['title'], $body, $book['language'], '');
    }

    /** @param Part $part */
    private function partPage(array $part, string $lang): string {
        $e = self::e(...);
        $n = count($part['articles']);
        $body = '<section class="part" epub:type="part">' . "\n"
            . '<h1>' . $e($part['title']) . '</h1>' . "\n"
            . ($part['folder'] !== '' ? '<p class="meta">' . $e($part['folder']) . '</p>' . "\n" : '')
            . '<p class="meta">' . $n . ' ' . ($n === 1 ? 'article' : 'articles') . '</p>' . "\n"
            . ($part['link'] !== '' && preg_match('#^https?://#i', $part['link']) ? '<p class="meta"><a href="' . $e($part['link']) . '">' . $e($this->host($part['link'])) . '</a></p>' . "\n" : '')
            . '</section>';
        return $this->page($part['title'], $body, $lang, '../');
    }

    /**
     * @param Article $article
     * @param Part $part
     */
    private function articlePage(array $article, array $part, string $lang): string {
        $e = self::e(...);
        $meta = array_filter([
            $part['title'],
            $article['author'],
            $article['pubDate'] > 0 ? $this->localTime($article['pubDate']) : '',
        ], static fn (string $s): bool => trim($s) !== '');
        $body = '<article epub:type="chapter"' . ($article['rtl'] ? ' dir="rtl"' : '') . '>' . "\n"
            . '<h1>' . $e($this->articleTitle($article)) . '</h1>' . "\n"
            . '<p class="meta">' . $e(implode(' · ', $meta)) . '</p>' . "\n"
            . $article['html'] . "\n"
            . (preg_match('#^https?://#i', $article['url']) ? '<p class="source"><a href="' . $e($article['url']) . '">Read the original on ' . $e($this->host($article['url'])) . '</a></p>' . "\n" : '')
            . '</article>';
        return $this->page($this->articleTitle($article), $body, $lang, '../');
    }

    private function page(string $title, string $body, string $lang, string $root): string {
        $e = self::e(...);
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE html>' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="' . $e($lang) . '" xml:lang="' . $e($lang) . '">' . "\n"
            . '<head><meta charset="UTF-8"/><title>' . $e($title) . '</title>'
            . '<link rel="stylesheet" type="text/css" href="' . $root . 'style.css"/></head>' . "\n"
            . '<body>' . "\n" . $body . "\n" . '</body>' . "\n" . '</html>' . "\n";
    }

    /** @param Article $article */
    private function articleTitle(array $article): string {
        $title = trim(html_entity_decode(strip_tags($article['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $title !== '' ? $title : 'Untitled';
    }

    private function host(string $url): string {
        $host = (string)parse_url($url, PHP_URL_HOST);
        return $host !== '' ? preg_replace('/^www\./', '', $host) ?? $host : $url;
    }

    /** Formatted in the reader's time zone. */
    private function localTime(int $timestamp): string {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone)->format('D j M Y, H:i');
    }

    private static function e(string $text): string {
        return htmlspecialchars(HtmlCleaner::xmlText($text), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
