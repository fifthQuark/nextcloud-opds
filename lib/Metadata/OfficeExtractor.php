<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

/**
 * Document properties of OOXML (docx) and ODF (odt) files.
 */
class OfficeExtractor {
    public function extract(string $localPath, string $format): ?Metadata {
        $zip = new \ZipArchive();
        if ($zip->open($localPath) !== true) {
            return null;
        }
        try {
            $meta = new Metadata();
            if ($format === 'docx') {
                $core = $zip->getFromName('docProps/core.xml');
                if ($core !== false) {
                    $this->parseCore($core, $meta);
                }
                $thumb = $zip->getFromName('docProps/thumbnail.jpeg');
                if ($thumb === false) {
                    $thumb = $zip->getFromName('docProps/thumbnail.png');
                }
                $meta->setCover($thumb === false ? null : $thumb);
            } else {
                $xml = $zip->getFromName('meta.xml');
                if ($xml !== false) {
                    $this->parseOdf($xml, $meta);
                }
                $thumb = $zip->getFromName('Thumbnails/thumbnail.png');
                $meta->setCover($thumb === false ? null : $thumb);
            }
            return $meta;
        } finally {
            $zip->close();
        }
    }

    private function parseCore(string $xml, Metadata $meta): void {
        $doc = Text::dom($xml);
        if ($doc === null) {
            return;
        }
        $xp = new \DOMXPath($doc);
        $meta->title = Text::firstText($xp, '//' . Text::ln('title'));
        foreach (Text::allText($xp, '//' . Text::ln('creator')) as $creator) {
            foreach (preg_split('/\s*;\s*/', $creator) ?: [] as $name) {
                $meta->addAuthor($name);
            }
        }
        $subject = Text::firstText($xp, '//' . Text::ln('subject'));
        if ($subject !== '') {
            $meta->addSubject($subject);
        }
        foreach (Text::allText($xp, '//' . Text::ln('keywords')) as $keywords) {
            foreach (preg_split('/\s*[,;]\s*/', $keywords) ?: [] as $kw) {
                $meta->addSubject($kw);
            }
        }
        $meta->description = Text::plain(Text::firstText($xp, '//' . Text::ln('description')));
        $meta->date = Text::firstText($xp, '//' . Text::ln('created'));
        $meta->language = Text::language(Text::firstText($xp, '//' . Text::ln('language')));
    }

    private function parseOdf(string $xml, Metadata $meta): void {
        $doc = Text::dom($xml);
        if ($doc === null) {
            return;
        }
        $xp = new \DOMXPath($doc);
        $meta->title = Text::firstText($xp, '//' . Text::ln('title'));
        $creator = Text::firstText($xp, '//' . Text::ln('initial-creator')) ?: Text::firstText($xp, '//' . Text::ln('creator'));
        if ($creator !== '') {
            $meta->addAuthor($creator);
        }
        $subject = Text::firstText($xp, '//' . Text::ln('subject'));
        if ($subject !== '') {
            $meta->addSubject($subject);
        }
        foreach (Text::allText($xp, '//' . Text::ln('keyword')) as $kw) {
            $meta->addSubject($kw);
        }
        $meta->description = Text::plain(Text::firstText($xp, '//' . Text::ln('description')));
        $meta->date = Text::firstText($xp, '//' . Text::ln('creation-date'));
        $meta->language = Text::language(Text::firstText($xp, '//' . Text::ln('language')));
    }
}
