<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

use OCA\Opds\Metadata\EpubExtractor;
use OCA\Opds\Metadata\Text;

/**
 * Puts what Calibre knows about a book into a copy of its EPUB file, as Calibre does
 * when it sends a book to a reader. Only what differs is changed, so that a book that
 * was never edited stays the same file, and the same edit always gives the same file.
 */
class EpubMetadata {
    private const OPF = 'http://www.idpf.org/2007/opf';
    private const DC = 'http://purl.org/dc/elements/1.1/';
    private const MAX_OPF = 10 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;
    /** Stands for the prefix of new elements until the document is written: DOM would choose one by itself */
    private const NEW = 'opds-new-element-';

    private \DOMDocument $doc;
    private \DOMXPath $xp;
    private \DOMElement $metadata;
    private bool $epub3 = false;
    private bool $changed = false;

    public function __construct(
        private EpubExtractor $reader,
    ) {
    }

    /**
     * @param string $path a copy that may be changed
     * @param array<string,mixed> $record see CalibreDb::get()
     * @param string|null $cover JPEG
     * @return bool false if the copy is of no use: nothing has to be changed, or it cannot be
     */
    public function write(string $path, array $record, ?string $cover): bool {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $open = true;
        try {
            // Signed or protected books must stay as they are
            foreach (['META-INF/signatures.xml', 'META-INF/rights.xml', 'META-INF/license.lcpl'] as $name) {
                if ($zip->locateName($name) !== false) {
                    return false;
                }
            }
            $opf = $this->reader->findOpfPath($zip);
            $stat = $opf !== null ? $zip->statName($opf) : false;
            if ($opf === null || $stat === false || $stat['size'] > self::MAX_OPF) {
                return false;
            }
            $encrypted = $this->encrypted($zip);
            if (isset($encrypted[$opf]) || !$this->load((string)$zip->getFromName($opf))) {
                return false;
            }
            $this->title($record);
            $this->authors($record);
            $this->single('publisher', $record['publisher']);
            $this->description($record['comments']);
            $this->languages($record['languages']);
            $this->subjects($record['tags']);
            $this->date($record);
            $this->identifiers($record['identifiers']);
            $this->series($record['series'], $record['series_index']);
            $this->named('calibre:rating', $record['rating'] > 0 ? (string)$record['rating'] : '', static fn (string $v): string => (float)$v > 0 ? (string)(int)round((float)$v) : '');
            $files = $cover !== null && Text::sniffImageMime($cover) === 'image/jpeg' ? $this->cover($zip, $opf, $encrypted, $cover) : [];
            if (!$this->changed && $files === []) {
                return false;
            }
            if ($this->changed) {
                $xml = $this->doc->saveXML();
                if ($xml === false) {
                    return false;
                }
                $prefix = (string)$this->metadata->prefix;
                $files[$opf] = str_replace(self::NEW, $prefix !== '' ? $prefix . ':' : '', $xml);
            }
            $first = $zip->getNameIndex(0);
            foreach ($files as $name => $data) {
                if (!$zip->addFromString((string)$name, $data)) {
                    return false;
                }
                // Not the current time: the same book must give the same file on all devices
                $zip->setMtimeName((string)$name, $stat['mtime']);
            }
            $open = false;
            return $zip->close() && $this->verify($path, $opf, $first, $files);
        } finally {
            if ($open) {
                $zip->unchangeAll();
                $zip->close();
            }
            unset($this->doc, $this->xp, $this->metadata);
        }
    }

    private function load(string $xml): bool {
        // A document type could define entities that are lost on saving
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false || str_contains($xml, self::NEW)) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        $this->doc = new \DOMDocument();
        $ok = $this->doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $package = $this->doc->documentElement;
        if (!$ok || $package === null || $package->localName !== 'package') {
            return false;
        }
        $this->doc->encoding = 'UTF-8';
        $this->xp = new \DOMXPath($this->doc);
        $metadata = $this->xp->query('/*/*[local-name()="metadata"]')->item(0);
        // <dc-metadata> is how OEB 1 did it
        if (!$metadata instanceof \DOMElement || $this->xp->query('*[local-name()="dc-metadata"]', $metadata)->length > 0) {
            return false;
        }
        $this->metadata = $metadata;
        $this->epub3 = (float)$package->getAttribute('version') >= 3;
        $this->changed = false;
        return true;
    }

    // ----------------------------------------------------------------- fields

    /** @param array<string,mixed> $record */
    private function title(array $record): void {
        $titles = $this->all('title');
        $main = $titles[0] ?? null;
        foreach ($titles as $node) {
            if ($this->refined($node, 'title-type') === 'main') {
                $main = $node;
                break;
            }
        }
        $subtitles = array_values(array_filter($titles, fn (\DOMElement $n): bool => $n !== $main && $this->refined($n, 'title-type') === 'subtitle'));
        $names = [];
        if ($main !== null) {
            $names[] = Text::clean($main->textContent);
            // Calibre makes one title of both
            foreach ($subtitles as $node) {
                $names[] = $names[0] . ': ' . Text::clean($node->textContent);
            }
        }
        $edited = !in_array(Text::clean($record['title']), $names, true);
        if ($main === null) {
            $this->add('title', $record['title'], $this->metadata->firstChild);
        } elseif ($edited) {
            $this->setText($main, $record['title']);
            array_map($this->remove(...), $subtitles);
        }
        // Readers sort well enough on their own unless the user chose something else
        $sort = $record['sort'];
        $usual = $sort === Naming::titleSort($record['title'], $record['languages'][0] ?? 'eng');
        if ($this->metas('calibre:title_sort') !== [] || ($sort !== '' && !$usual && ($edited || $this->fileAs($main) !== $sort))) {
            $this->named('calibre:title_sort', $sort);
        }
        if ($main !== null && ($edited || !$usual)) {
            $this->refine($main, 'file-as', $usual || $sort === '' ? null : $sort, $edited);
        }
    }

    /** @param array<string,mixed> $record */
    private function authors(array $record): void {
        if ($record['authors'] === [] || $record['authors'] === ['Unknown']) {
            return;
        }
        $old = array_values(array_filter($this->all('creator'), fn (\DOMElement $n): bool => in_array($this->role($n), ['', 'aut'], true)));
        $names = array_map(static fn (\DOMElement $n): string => Text::clean($n->textContent), $old);
        // The sort name of this book comes before the one the author has in general
        $sorts = array_map('trim', explode('&', $record['author_sort']));
        if (count($sorts) !== count($record['authors']) || in_array('', $sorts, true)) {
            $sorts = array_map(static fn (string $name): string => $record['author_sorts'][$name] ?? Naming::authorSort($name), $record['authors']);
        }
        if ($names === array_map(static fn (string $n): string => Text::clean($n), $record['authors'])) {
            foreach ($old as $i => $node) {
                $fileAs = $this->fileAs($node);
                if ($fileAs !== $sorts[$i] && ($fileAs !== '' || $sorts[$i] !== Naming::authorSort($record['authors'][$i]))) {
                    $this->setFileAs($node, $sorts[$i]);
                }
            }
            return;
        }
        if ($old === []) {
            // Where nobody is named as the author, editors and others are taken for them
            $all = array_map(static fn (\DOMElement $n): string => Text::clean($n->textContent), $this->all('creator'));
            if ($all === $record['authors']) {
                return;
            }
        }
        $before = $old !== [] ? $this->after(end($old)) : $this->after($this->all('title')[0] ?? null);
        foreach ($record['authors'] as $i => $name) {
            $node = $this->add('creator', $name, $before);
            if ($this->epub3) {
                $node->setAttribute('id', $this->freeId('opds-author-' . ($i + 1)));
                $this->refine($node, 'role', 'aut', true, 'marc:relators');
            } else {
                $node->setAttributeNS(self::OPF, 'opf:role', 'aut');
            }
            $this->setFileAs($node, $sorts[$i]);
        }
        array_map($this->remove(...), $old);
    }

    private function single(string $name, string $value): void {
        $nodes = $this->all($name);
        if ($value === '') {
            array_map($this->remove(...), $nodes);
        } elseif ($nodes === []) {
            $this->add($name, $value);
        } elseif (Text::clean($nodes[0]->textContent) !== Text::clean($value)) {
            $this->setText($nodes[0], $value);
        }
    }

    private function description(string $html): void {
        $nodes = $this->all('description');
        $plain = static fn (string $s): string => Text::plain($s, 1_000_000);
        if ($plain($nodes !== [] ? $nodes[0]->textContent : '') !== $plain($html)) {
            $this->single('description', $html);
        }
    }

    /** @param list<string> $codes */
    private function languages(array $codes): void {
        $nodes = $this->all('language');
        // An EPUB must have a language
        if ($codes === [] || $codes === array_map(static fn (\DOMElement $n): ?string => Languages::toCalibre($n->textContent), $nodes)) {
            return;
        }
        $before = $nodes !== [] ? $this->after(end($nodes)) : null;
        foreach ($codes as $code) {
            $this->add('language', Languages::fromCalibre($code), $before);
        }
        array_map($this->remove(...), $nodes);
    }

    /** @param list<string> $tags */
    private function subjects(array $tags): void {
        $nodes = $this->all('subject');
        $old = [];
        foreach ($nodes as $node) {
            // Calibre takes a comma for the end of a tag
            foreach (explode(',', $node->textContent) as $tag) {
                $old[] = Text::clean($tag);
            }
        }
        $old = array_values(array_unique(array_filter($old, static fn (string $t): bool => $t !== '')));
        $new = array_map(static fn (string $t): string => Text::clean($t), $tags);
        sort($old);
        sort($new);
        if ($old === $new) {
            return;
        }
        $before = $nodes !== [] ? $this->after(end($nodes)) : null;
        foreach ($tags as $tag) {
            $this->add('subject', $tag, $before);
        }
        array_map($this->remove(...), $nodes);
    }

    /** @param array<string,mixed> $record */
    private function date(array $record): void {
        $nodes = array_values(array_filter($this->all('date'),
            fn (\DOMElement $n): bool => in_array(strtolower($this->opfAttr($n, 'event')), ['', 'publication', 'original-publication', 'ops-publication'], true)));
        $chosen = null;
        foreach ($nodes as $node) {
            if ($chosen === null || ($this->opfAttr($chosen, 'event') === '' && $this->opfAttr($node, 'event') !== '')) {
                $chosen = $node;
            }
        }
        $old = $chosen !== null ? Text::date(Text::clean($chosen->textContent)) : '';
        $new = $record['pubdate'];
        if ($new === '') {
            if ($old !== '') {
                array_map($this->remove(...), $nodes);
            }
            return;
        }
        $dates = array_filter(array_map(static fn (\DOMElement $n): string => Text::date(Text::clean($n->textContent)), $this->all('date')));
        // "2020" is the same date as far as it goes, and midnight is on another day elsewhere
        $day = new \DateTimeImmutable($new);
        foreach (['-1 day', '+0 days', '+1 day'] as $shift) {
            foreach ($dates as $date) {
                if (str_starts_with($day->modify($shift)->format('Y-m-d'), $date)) {
                    return;
                }
            }
        }
        if ($chosen !== null) {
            $this->setText($chosen, $new);
            return;
        }
        $node = $this->add('date', $new);
        if (!$this->epub3) {
            $node->setAttributeNS(self::OPF, 'opf:event', 'publication');
        }
    }

    /** @param array<string,string> $wanted */
    private function identifiers(array $wanted): void {
        $uid = $this->doc->documentElement?->getAttribute('unique-identifier') ?? '';
        $nodes = $this->all('identifier');
        // The app does not change how the book identifies itself: fonts may be encoded with it
        $own = static fn (\DOMElement $n): bool => ($uid !== '' && $n->getAttribute('id') === $uid) || count($nodes) === 1;
        unset($wanted['uuid'], $wanted['calibre']);
        foreach ($wanted as $type => $value) {
            $type = (string)$type;
            $same = array_values(array_filter($nodes, fn (\DOMElement $n): bool => $this->identifier($n)[0] === $type));
            $equal = array_filter($same, fn (\DOMElement $n): bool => $this->identifier($n)[1] === ($type === 'isbn' ? (Text::isbn($value) ?: $value) : $value));
            if ($equal !== []) {
                continue;
            }
            $free = array_values(array_filter($same, static fn (\DOMElement $n): bool => !$own($n)));
            array_map($this->remove(...), $free);
            $text = $this->epub3 ? ($type === 'isbn' ? 'urn:isbn:' : $type . ':') . $value : $value;
            $node = $this->add('identifier', $text);
            if (!$this->epub3) {
                $node->setAttributeNS(self::OPF, 'opf:scheme', strtoupper($type));
            }
        }
        if (!isset($wanted['isbn'])) {
            foreach ($nodes as $node) {
                if (!$own($node) && $this->identifier($node)[0] === 'isbn') {
                    $this->remove($node);
                }
            }
        }
    }

    /** @return array{0: string, 1: string} type and value */
    private function identifier(\DOMElement $node): array {
        $value = Text::clean($node->textContent);
        $type = strtolower($this->opfAttr($node, 'scheme'));
        if (preg_match('/^(?:urn:)?([a-z][a-z0-9_.-]*):(?!\/\/)(.+)$/i', $value, $m)) {
            $type = $type !== '' ? $type : strtolower($m[1]);
            $value = $type === strtolower($m[1]) ? $m[2] : $value;
        }
        $isbn = Text::isbn($value);
        if (($type === 'isbn' || $type === '') && $isbn !== '' && ($type === 'isbn' || strlen($isbn) === 13)) {
            return ['isbn', $isbn];
        }
        return [$type, $value];
    }

    private function series(string $name, float $index): void {
        $old = '';
        $oldIndex = 0.0;
        foreach ($this->metas('calibre:series') as $node) {
            $old = Text::clean($node->getAttribute('content'));
        }
        foreach ($this->metas('calibre:series_index') as $node) {
            $oldIndex = (float)$node->getAttribute('content');
        }
        $collections = array_values(array_filter($this->properties('belongs-to-collection'),
            fn (\DOMElement $n): bool => in_array($this->refined($n, 'collection-type'), ['', 'series'], true)));
        if ($old === '' && $collections !== []) {
            $old = Text::clean($collections[0]->textContent);
            $oldIndex = (float)$this->refined($collections[0], 'group-position');
        }
        if (Text::clean($name) === $old && ($old === '' || abs($index - $oldIndex) < 0.005)) {
            return;
        }
        array_map($this->remove(...), [...$this->metas('calibre:series'), ...$this->metas('calibre:series_index'), ...$collections]);
        if ($name === '') {
            return;
        }
        $number = rtrim(rtrim(sprintf('%.2F', $index), '0'), '.');
        // The old way as well: many readers know nothing else
        $this->named('calibre:series', $name);
        $this->named('calibre:series_index', $number);
        if ($this->epub3) {
            $node = $this->addMeta(['property' => 'belongs-to-collection', 'id' => $this->freeId('opds-series')], $name);
            $this->refine($node, 'collection-type', 'series', true);
            $this->refine($node, 'group-position', $number, true);
        }
    }

    /**
     * @param array<string,true> $encrypted
     * @return array<string,string> files to write
     */
    private function cover(\ZipArchive $zip, string $opf, array $encrypted, string $jpeg): array {
        $manifest = $this->xp->query('/*/*[local-name()="manifest"]')->item(0);
        if (!$manifest instanceof \DOMElement) {
            return [];
        }
        $items = [];
        $item = null;
        foreach ($this->xp->query('*[local-name()="item"]', $manifest) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $items[$node->getAttribute('id')] = $node;
                if (in_array('cover-image', preg_split('/\s+/', $node->getAttribute('properties')) ?: [], true)) {
                    $item ??= $node;
                }
            }
        }
        foreach ($this->metas('cover') as $node) {
            $item ??= $items[$node->getAttribute('content')] ?? null;
        }
        if ($item !== null) {
            $name = $this->reader->resolve($opf, $item->getAttribute('href'));
            $stat = $zip->statName($name);
            $old = $stat !== false && $stat['size'] <= CoverImage::MAX_BYTES ? (string)$zip->getFromName($name) : '';
            if (isset($encrypted[$name]) || ($stat !== false && $old === '') || $old === $jpeg || ($old !== '' && $this->sameImage($old, $jpeg))) {
                return [];
            }
            $new = match (Text::sniffImageMime($old)) {
                'image/jpeg' => $jpeg,
                'image/png' => $this->toPng($jpeg),
                default => null,
            };
            if ($new !== null) {
                return [$name => $new];
            }
        }
        // No cover yet, or one in a format that cannot be written
        $href = 'opds-cover.jpg';
        $name = $this->reader->resolve($opf, $href);
        if ($zip->locateName($name) !== false) {
            return [];
        }
        $node = $this->doc->createElement(self::NEW . 'item');
        $node->setAttribute('id', $this->freeId('opds-cover'));
        $node->setAttribute('href', $href);
        $node->setAttribute('media-type', 'image/jpeg');
        if ($this->epub3) {
            if ($item !== null) {
                $rest = array_diff(preg_split('/\s+/', trim($item->getAttribute('properties'))) ?: [], ['cover-image', '']);
                $rest !== [] ? $item->setAttribute('properties', implode(' ', $rest)) : $item->removeAttribute('properties');
            }
            $node->setAttribute('properties', 'cover-image');
        }
        $manifest->appendChild($node);
        $manifest->appendChild($this->doc->createTextNode("\n"));
        $this->named('cover', $node->getAttribute('id'));
        $this->changed = true;
        return [$name => $jpeg];
    }

    /** Whether one picture is just the other one, saved again. */
    private function sameImage(string $a, string $b): bool {
        $small = [];
        foreach ([$a, $b] as $data) {
            $size = @getimagesizefromstring($data);
            if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS || !function_exists('imagecreatefromstring')) {
                return false;
            }
            $image = @imagecreatefromstring($data);
            if ($image === false) {
                return false;
            }
            $canvas = imagecreatetruecolor(24, 32);
            imagefill($canvas, 0, 0, (int)imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, 24, 32, $size[0], $size[1]);
            $small[] = ['ratio' => $size[0] / $size[1], 'image' => $canvas];
        }
        if (abs($small[0]['ratio'] / $small[1]['ratio'] - 1) > 0.03) {
            return false;
        }
        $worst = 0;
        $sum = 0;
        for ($x = 0; $x < 24; $x++) {
            for ($y = 0; $y < 32; $y++) {
                $p = (int)imagecolorat($small[0]['image'], $x, $y);
                $q = (int)imagecolorat($small[1]['image'], $x, $y);
                foreach ([16, 8, 0] as $shift) {
                    $d = abs((($p >> $shift) & 255) - (($q >> $shift) & 255));
                    $sum += $d;
                    $worst = max($worst, $d);
                }
            }
        }
        return $sum / (24 * 32 * 3) < 2.5 && $worst < 40;
    }

    private function toPng(string $jpeg): ?string {
        $size = @getimagesizefromstring($jpeg);
        if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS || !function_exists('imagepng')) {
            return null;
        }
        $image = @imagecreatefromstring($jpeg);
        if ($image === false) {
            return null;
        }
        ob_start();
        imagepng($image, null, 6);
        return (string)ob_get_clean() ?: null;
    }

    // ---------------------------------------------------------------- package

    /** @return array<string,true> */
    private function encrypted(\ZipArchive $zip): array {
        $xml = $zip->getFromName('META-INF/encryption.xml', 1024 * 1024);
        $doc = $xml !== false ? Text::dom($xml) : null;
        $out = [];
        foreach ($doc !== null ? (new \DOMXPath($doc))->query('//*[local-name()="CipherReference"]') ?: [] : [] as $node) {
            if ($node instanceof \DOMElement) {
                $out[ltrim(rawurldecode($node->getAttribute('URI')), '/')] = true;
            }
        }
        return $out;
    }

    /** @param array<string,string> $files */
    private function verify(string $path, string $opf, string|false $first, array $files): bool {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        try {
            if ($zip->getNameIndex(0) !== $first || $this->reader->findOpfPath($zip) !== $opf) {
                return false;
            }
            foreach ($files as $name => $data) {
                if ($zip->getFromName((string)$name) !== $data) {
                    return false;
                }
            }
            return true;
        } finally {
            $zip->close();
        }
    }

    /** @return list<\DOMElement> elements of Dublin Core */
    private function all(string $name): array {
        return $this->elements('*[local-name()="' . $name . '" and (namespace-uri()="' . self::DC . '" or namespace-uri()="")]');
    }

    /** @return list<\DOMElement> <meta name="…" content="…"/> */
    private function metas(string $name): array {
        return $this->elements('*[local-name()="meta" and @name="' . $name . '"]');
    }

    /** @return list<\DOMElement> <meta property="…">…</meta> */
    private function properties(string $property): array {
        return $this->elements('*[local-name()="meta" and @property="' . $property . '"]');
    }

    /** @return list<\DOMElement> */
    private function elements(string $path): array {
        $out = [];
        foreach ($this->xp->query($path, $this->metadata) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /** @return list<\DOMElement> <meta refines="#id" property="…"> */
    private function refinements(\DOMElement $node, ?string $property = null): array {
        $id = $node->getAttribute('id');
        return $id === '' ? [] : array_values(array_filter($this->elements('*[local-name()="meta" and @refines]'),
            static fn (\DOMElement $m): bool => ltrim($m->getAttribute('refines'), '#') === $id && ($property === null || $m->getAttribute('property') === $property)));
    }

    private function refined(\DOMElement $node, string $property): string {
        $metas = $this->refinements($node, $property);
        return $metas !== [] ? Text::clean($metas[0]->textContent) : '';
    }

    /** @param string|null $value null: remove it if there is one and $edited */
    private function refine(\DOMElement $node, string $property, ?string $value, bool $edited, string $scheme = ''): void {
        $metas = $this->refinements($node, $property);
        if ($value === null) {
            if ($edited) {
                array_map($this->remove(...), $metas);
            }
            return;
        }
        if (!$this->epub3) {
            return;
        }
        if ($metas !== []) {
            if (Text::clean($metas[0]->textContent) !== $value) {
                $this->setText($metas[0], $value);
            }
            return;
        }
        if ($node->getAttribute('id') === '') {
            $node->setAttribute('id', $this->freeId('opds-' . $node->localName));
        }
        $this->addMeta(['refines' => '#' . $node->getAttribute('id'), 'property' => $property] + ($scheme !== '' ? ['scheme' => $scheme] : []), $value);
    }

    private function role(\DOMElement $node): string {
        return strtolower($this->opfAttr($node, 'role') ?: $this->refined($node, 'role'));
    }

    private function fileAs(\DOMElement $node): string {
        return Text::clean($this->opfAttr($node, 'file-as') ?: $this->refined($node, 'file-as'));
    }

    private function setFileAs(\DOMElement $node, string $value): void {
        if ($value === '') {
            return;
        }
        if ($this->epub3 && $this->opfAttr($node, 'file-as') === '') {
            $this->refine($node, 'file-as', $value, true);
            return;
        }
        $node->setAttributeNS(self::OPF, 'opf:file-as', $value);
        $this->changed = true;
    }

    /**
     * @param (callable(string): string)|null $normalise for comparing
     */
    private function named(string $name, string $value, ?callable $normalise = null): void {
        $metas = $this->metas($name);
        if ($value === '') {
            array_map($this->remove(...), $metas);
            return;
        }
        if ($metas === []) {
            $this->addMeta(['name' => $name, 'content' => $value]);
            return;
        }
        $old = $metas[0]->getAttribute('content');
        if (($normalise !== null ? $normalise($old) : $old) !== $value) {
            $metas[0]->setAttribute('content', $value);
            $this->changed = true;
        }
    }

    /** @param array<string,string> $attributes */
    private function addMeta(array $attributes, string $text = ''): \DOMElement {
        $node = $this->doc->createElement(self::NEW . 'meta');
        foreach ($attributes as $name => $value) {
            $node->setAttribute($name, self::valid($value));
        }
        if ($text !== '') {
            $node->appendChild($this->doc->createTextNode(self::valid($text)));
        }
        $this->metadata->appendChild($node);
        $this->metadata->appendChild($this->doc->createTextNode("\n"));
        $this->changed = true;
        return $node;
    }

    private function add(string $name, string $text, ?\DOMNode $before = null): \DOMElement {
        $node = $this->doc->createElementNS(self::DC, 'dc:' . $name);
        $node->appendChild($this->doc->createTextNode(self::valid($text)));
        $this->metadata->insertBefore($node, $before);
        $this->metadata->insertBefore($this->doc->createTextNode("\n"), $before);
        $this->changed = true;
        return $node;
    }

    private function after(?\DOMNode $node): ?\DOMNode {
        return $node?->nextSibling;
    }

    private function setText(\DOMElement $node, string $text): void {
        while ($node->firstChild !== null) {
            $node->removeChild($node->firstChild);
        }
        $node->appendChild($this->doc->createTextNode(self::valid($text)));
        $this->changed = true;
    }

    private function remove(\DOMElement $node): void {
        foreach ($this->refinements($node) as $meta) {
            $meta->parentNode?->removeChild($meta);
        }
        $node->parentNode?->removeChild($node);
        $this->changed = true;
    }

    private function freeId(string $id): string {
        $free = $id;
        for ($n = 2; $this->xp->query('//*[@id="' . $free . '"]')->length > 0; $n++) {
            $free = $id . '-' . $n;
        }
        return $free;
    }

    private function opfAttr(\DOMElement $node, string $name): string {
        return $node->getAttributeNS(self::OPF, $name) ?: ($node->getAttribute('opf:' . $name) ?: $node->getAttribute($name));
    }

    /** Without characters that XML does not allow. */
    private static function valid(string $text): string {
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', Text::utf8($text)) ?? '';
    }
}
