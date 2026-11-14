<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

/**
 * FictionBook 2 (.fb2 and .fb2.zip). Only the <description> element and the
 * cover binary are parsed, so very large books stay cheap.
 */
class Fb2Extractor {
    /** Common FictionBook genre codes */
    private const GENRES = [
        'sf' => 'Science Fiction', 'sf_fantasy' => 'Fantasy', 'sf_action' => 'Science Fiction Action', 'sf_space' => 'Space Opera',
        'sf_social' => 'Social Science Fiction', 'sf_horror' => 'Horror', 'sf_humor' => 'Humorous Science Fiction',
        'sf_detective' => 'Science Fiction Detective', 'sf_cyberpunk' => 'Cyberpunk', 'sf_history' => 'Alternative History',
        'sf_heroic' => 'Heroic Fantasy', 'sf_epic' => 'Epic Fantasy', 'sf_postapocalyptic' => 'Post-apocalyptic',
        'detective' => 'Detective', 'det_classic' => 'Classic Detective', 'det_police' => 'Police Procedural', 'det_action' => 'Action',
        'det_irony' => 'Ironic Detective', 'det_history' => 'Historical Detective', 'det_espionage' => 'Espionage', 'det_crime' => 'Crime',
        'det_political' => 'Political Thriller', 'det_maniac' => 'Maniac', 'det_hard' => 'Hard-boiled', 'thriller' => 'Thriller',
        'prose' => 'Prose', 'prose_classic' => 'Classic Prose', 'prose_history' => 'Historical Prose', 'prose_contemporary' => 'Contemporary Prose',
        'prose_counter' => 'Counterculture', 'prose_rus_classic' => 'Russian Classics', 'prose_su_classics' => 'Soviet Classics',
        'love' => 'Romance', 'love_contemporary' => 'Contemporary Romance', 'love_history' => 'Historical Romance',
        'love_detective' => 'Romantic Suspense', 'love_short' => 'Short Romance', 'love_erotica' => 'Erotica',
        'adventure' => 'Adventure', 'adv_western' => 'Western', 'adv_history' => 'Historical Adventure', 'adv_indian' => 'Indian Adventure',
        'adv_maritime' => 'Maritime Adventure', 'adv_geo' => 'Travel', 'adv_animal' => 'Nature and Animals',
        'child_tale' => 'Fairy Tales', 'child_verse' => "Children's Verse", 'child_prose' => "Children's Prose", 'child_sf' => "Children's Science Fiction",
        'child_det' => "Children's Detective", 'child_adv' => "Children's Adventure", 'child_education' => "Children's Education", 'children' => "Children's",
        'poetry' => 'Poetry', 'dramaturgy' => 'Drama', 'antique' => 'Antique Literature', 'antique_ant' => 'Ancient Literature',
        'antique_european' => 'European Antique Literature', 'antique_russian' => 'Old Russian Literature', 'antique_east' => 'Eastern Antique Literature',
        'antique_myths' => 'Myths and Legends', 'sci_history' => 'History', 'sci_psychology' => 'Psychology', 'sci_culture' => 'Culture',
        'sci_religion' => 'Religion', 'sci_philosophy' => 'Philosophy', 'sci_politics' => 'Politics', 'sci_business' => 'Business',
        'sci_juris' => 'Law', 'sci_linguistic' => 'Linguistics', 'sci_medicine' => 'Medicine', 'sci_phys' => 'Physics', 'sci_math' => 'Mathematics',
        'sci_chem' => 'Chemistry', 'sci_biology' => 'Biology', 'sci_tech' => 'Technology', 'science' => 'Science',
        'comp_www' => 'Internet', 'comp_programming' => 'Programming', 'comp_hard' => 'Hardware', 'comp_soft' => 'Software',
        'comp_db' => 'Databases', 'comp_osnet' => 'Operating Systems and Networks', 'computers' => 'Computers',
        'ref_encyc' => 'Encyclopedia', 'ref_dict' => 'Dictionary', 'ref_ref' => 'Reference', 'ref_guide' => 'Guide', 'reference' => 'Reference',
        'nonf_biography' => 'Biography', 'nonf_publicism' => 'Journalism', 'nonf_criticism' => 'Criticism', 'nonfiction' => 'Non-fiction',
        'design' => 'Design', 'religion_rel' => 'Religion', 'religion_esoterics' => 'Esoterics', 'religion_self' => 'Self-improvement', 'religion' => 'Religion',
        'humor_anecdote' => 'Anecdotes', 'humor_prose' => 'Humorous Prose', 'humor_verse' => 'Humorous Verse', 'humor' => 'Humour',
        'home_cooking' => 'Cooking', 'home_pets' => 'Pets', 'home_crafts' => 'Crafts', 'home_entertain' => 'Entertainment', 'home_health' => 'Health',
        'home_garden' => 'Gardening', 'home_diy' => 'Do It Yourself', 'home_sport' => 'Sports', 'home_sex' => 'Sexuality', 'home' => 'Home',
    ];

    public function extract(string $localPath, bool $zipped): ?Metadata {
        if ($zipped) {
            $zip = new \ZipArchive();
            if ($zip->open($localPath) !== true) {
                return null;
            }
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (is_string($name) && str_ends_with(strtolower($name), '.fb2')) {
                        $xml = $zip->getFromIndex($i);
                        return $xml === false ? null : $this->parse($xml);
                    }
                }
                return null;
            } finally {
                $zip->close();
            }
        }
        $xml = file_get_contents($localPath);
        return $xml === false ? null : $this->parse($xml);
    }

    private function parse(string $xml): ?Metadata {
        $declaration = '';
        if (preg_match('/^\s*<\?xml[^>]*\?>/s', $xml, $m)) {
            $declaration = $m[0];
        }
        if (!preg_match('/<description[\s>].*?<\/description>/s', $xml, $m)) {
            return null;
        }
        $doc = Text::dom($declaration . '<FictionBook>' . $m[0] . '</FictionBook>');
        if ($doc === null) {
            return null;
        }
        $xp = new \DOMXPath($doc);
        $ti = '//' . Text::ln('title-info') . '/';
        $meta = new Metadata();
        $meta->title = Text::firstText($xp, $ti . Text::ln('book-title'));

        $authors = $xp->query($ti . Text::ln('author'));
        if ($authors !== false) {
            foreach ($authors as $author) {
                $first = Text::firstText($xp, Text::ln('first-name'), $author);
                $middle = Text::firstText($xp, Text::ln('middle-name'), $author);
                $last = Text::firstText($xp, Text::ln('last-name'), $author);
                $nick = Text::firstText($xp, Text::ln('nickname'), $author);
                $name = trim(implode(' ', array_filter([$first, $middle, $last])));
                $meta->addAuthor($name !== '' ? $name : $nick, $last !== '' ? $last . ', ' . trim($first . ' ' . $middle) : '');
            }
        }
        foreach (Text::allText($xp, $ti . Text::ln('genre')) as $genre) {
            $meta->addSubject(self::GENRES[$genre] ?? ucfirst(str_replace('_', ' ', $genre)));
        }
        $meta->language = Text::language(Text::firstText($xp, $ti . Text::ln('lang')));
        $meta->description = Text::plain($this->innerXml($xp, $ti . Text::ln('annotation')));
        $meta->date = Text::firstText($xp, $ti . Text::ln('date'));

        $sequence = $xp->query($ti . Text::ln('sequence'));
        if ($sequence !== false && $sequence->length > 0) {
            /** @var \DOMElement $seq */
            $seq = $sequence->item(0);
            $meta->series = Text::clean($seq->getAttribute('name'));
            $meta->seriesIndex = (float)$seq->getAttribute('number');
        }

        $pi = '//' . Text::ln('publish-info') . '/';
        $meta->publisher = Text::firstText($xp, $pi . Text::ln('publisher'));
        $meta->isbn = Text::isbn(Text::firstText($xp, $pi . Text::ln('isbn')));
        if ($meta->date === '') {
            $meta->date = Text::firstText($xp, $pi . Text::ln('year'));
        }

        // Cover: <coverpage><image l:href="#cover.jpg"/> -> <binary id="cover.jpg">
        $images = $xp->query($ti . Text::ln('coverpage', 'image'));
        if ($images !== false && $images->length > 0) {
            /** @var \DOMElement $img */
            $img = $images->item(0);
            $href = '';
            foreach ($img->attributes ?? [] as $attr) {
                if ($attr->localName === 'href') {
                    $href = ltrim($attr->nodeValue ?? '', '#');
                    break;
                }
            }
            if ($href !== '' && preg_match('/<binary[^>]*\bid\s*=\s*"' . preg_quote($href, '/') . '"[^>]*>(.*?)<\/binary>/s', $xml, $bm)) {
                $data = base64_decode(preg_replace('/\s+/', '', $bm[1]) ?? '', true);
                $meta->setCover($data === false ? null : $data);
            }
        }
        return $meta;
    }

    private function innerXml(\DOMXPath $xp, string $query): string {
        $nodes = $xp->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $node->ownerDocument?->saveXML($child) ?? '';
        }
        return $out;
    }
}
