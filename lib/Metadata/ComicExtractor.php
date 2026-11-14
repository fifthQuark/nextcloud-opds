<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

/**
 * Comic book archives: ComicInfo.xml metadata and the first page as cover.
 * CBZ is read with ZipArchive, CBR only when the PHP rar extension exists.
 */
class ComicExtractor {
    public function extract(string $localPath, string $format): ?Metadata {
        if ($format === 'cbz') {
            return $this->extractZip($localPath);
        }
        if ($format === 'cbr' && class_exists(\RarArchive::class)) {
            return $this->extractRar($localPath);
        }
        return null;
    }

    private function extractZip(string $localPath): ?Metadata {
        $zip = new \ZipArchive();
        if ($zip->open($localPath) !== true) {
            return null;
        }
        try {
            $meta = new Metadata();
            $images = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name)) {
                    continue;
                }
                if (strcasecmp(basename($name), 'ComicInfo.xml') === 0) {
                    $xml = $zip->getFromIndex($i);
                    if ($xml !== false) {
                        $this->parseComicInfo($xml, $meta);
                    }
                } elseif ($this->isPage($name)) {
                    $images[] = $name;
                }
            }
            $cover = $this->pickCover($images);
            if ($cover !== null) {
                $data = $zip->getFromName($cover);
                $meta->setCover($data === false ? null : $data);
            }
            return $meta;
        } finally {
            $zip->close();
        }
    }

    private function extractRar(string $localPath): ?Metadata {
        $rar = @\RarArchive::open($localPath);
        if ($rar === false) {
            return null;
        }
        try {
            $meta = new Metadata();
            $images = [];
            $entries = $rar->getEntries();
            if ($entries === false) {
                return $meta;
            }
            foreach ($entries as $entry) {
                $name = $entry->getName();
                if (strcasecmp(basename($name), 'ComicInfo.xml') === 0) {
                    $stream = $entry->getStream();
                    if ($stream !== false) {
                        $xml = stream_get_contents($stream);
                        fclose($stream);
                        if ($xml !== false) {
                            $this->parseComicInfo($xml, $meta);
                        }
                    }
                } elseif ($this->isPage($name)) {
                    $images[$name] = $entry;
                }
            }
            $cover = $this->pickCover(array_keys($images));
            if ($cover !== null) {
                $stream = $images[$cover]->getStream();
                if ($stream !== false) {
                    $data = stream_get_contents($stream);
                    fclose($stream);
                    $meta->setCover($data === false ? null : $data);
                }
            }
            return $meta;
        } finally {
            $rar->close();
        }
    }

    private function isPage(string $name): bool {
        $base = basename($name);
        return Text::isImageName($base) && !str_starts_with($base, '.') && !str_contains($name, '__MACOSX');
    }

    /** @param string[] $images */
    private function pickCover(array $images): ?string {
        if ($images === []) {
            return null;
        }
        foreach ($images as $name) {
            if (preg_match('/cover|^0*1?[^0-9]*$/i', pathinfo($name, PATHINFO_FILENAME))) {
                if (preg_match('/cover/i', basename($name))) {
                    return $name;
                }
            }
        }
        natcasesort($images);
        return reset($images) ?: null;
    }

    private function parseComicInfo(string $xml, Metadata $meta): void {
        $doc = Text::dom($xml);
        if ($doc === null) {
            return;
        }
        $xp = new \DOMXPath($doc);
        $get = static fn (string $name): string => Text::firstText($xp, '/' . Text::ln('ComicInfo', $name));

        $series = $get('Series');
        $number = $get('Number');
        $title = $get('Title');
        $volume = $get('Volume');
        if ($title === '') {
            $title = $series;
            if ($volume !== '' && $number === '') {
                $title .= ' Vol. ' . $volume;
            }
            if ($number !== '') {
                $title .= ' #' . $number;
            }
        }
        $meta->title = $title;
        $meta->series = $series;
        $meta->seriesIndex = (float)$number;

        foreach (['Writer', 'Penciller', 'Inker', 'CoverArtist', 'Colorist'] as $role) {
            $value = $get($role);
            if ($value !== '') {
                foreach (preg_split('/\s*,\s*/', $value) ?: [] as $name) {
                    $meta->addAuthor($name);
                }
                if ($role === 'Writer' && $meta->authors !== []) {
                    break;
                }
            }
        }

        $meta->publisher = $get('Publisher');
        $meta->description = Text::plain($get('Summary'));
        $meta->language = Text::language($get('LanguageISO'));
        foreach (preg_split('/\s*,\s*/', $get('Genre')) ?: [] as $genre) {
            $meta->addSubject($genre);
        }
        foreach (preg_split('/\s*,\s*/', $get('Tags')) ?: [] as $tag) {
            $meta->addSubject($tag);
        }
        $year = $get('Year');
        if ($year !== '') {
            $meta->date = $year;
            $month = $get('Month');
            if ($month !== '') {
                $meta->date .= '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
                $day = $get('Day');
                if ($day !== '') {
                    $meta->date .= '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
                }
            }
        }
    }
}
