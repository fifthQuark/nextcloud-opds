<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

/**
 * Calibre stores ISO 639-3 codes ("eng"), books and people use "en" or "English".
 */
final class Languages {
    private const LIST = [
        'afr' => ['af', 'Afrikaans'], 'ara' => ['ar', 'Arabic'], 'bel' => ['be', 'Belarusian'], 'ben' => ['bn', 'Bengali'],
        'bos' => ['bs', 'Bosnian'], 'bul' => ['bg', 'Bulgarian'], 'cat' => ['ca', 'Catalan'], 'ces' => ['cs', 'Czech'],
        'cym' => ['cy', 'Welsh'], 'dan' => ['da', 'Danish'], 'deu' => ['de', 'German'], 'ell' => ['el', 'Greek'],
        'eng' => ['en', 'English'], 'epo' => ['eo', 'Esperanto'], 'est' => ['et', 'Estonian'], 'eus' => ['eu', 'Basque'],
        'fas' => ['fa', 'Persian'], 'fin' => ['fi', 'Finnish'], 'fra' => ['fr', 'French'], 'gle' => ['ga', 'Irish'],
        'glg' => ['gl', 'Galician'], 'heb' => ['he', 'Hebrew'], 'hin' => ['hi', 'Hindi'], 'hrv' => ['hr', 'Croatian'],
        'hun' => ['hu', 'Hungarian'], 'hye' => ['hy', 'Armenian'], 'ind' => ['id', 'Indonesian'], 'isl' => ['is', 'Icelandic'],
        'ita' => ['it', 'Italian'], 'jpn' => ['ja', 'Japanese'], 'kat' => ['ka', 'Georgian'], 'kaz' => ['kk', 'Kazakh'],
        'kor' => ['ko', 'Korean'], 'lat' => ['la', 'Latin'], 'lav' => ['lv', 'Latvian'], 'lit' => ['lt', 'Lithuanian'],
        'ltz' => ['lb', 'Luxembourgish'], 'mkd' => ['mk', 'Macedonian'], 'mlt' => ['mt', 'Maltese'], 'msa' => ['ms', 'Malay'],
        'nld' => ['nl', 'Dutch'], 'nno' => ['nn', 'Norwegian Nynorsk'], 'nob' => ['nb', 'Norwegian Bokmål'], 'nor' => ['no', 'Norwegian'],
        'oci' => ['oc', 'Occitan'], 'pol' => ['pl', 'Polish'], 'por' => ['pt', 'Portuguese'], 'ron' => ['ro', 'Romanian'],
        'rus' => ['ru', 'Russian'], 'slk' => ['sk', 'Slovak'], 'slv' => ['sl', 'Slovenian'], 'spa' => ['es', 'Spanish'],
        'sqi' => ['sq', 'Albanian'], 'srp' => ['sr', 'Serbian'], 'swe' => ['sv', 'Swedish'], 'swa' => ['sw', 'Swahili'],
        'tam' => ['ta', 'Tamil'], 'tel' => ['te', 'Telugu'], 'tha' => ['th', 'Thai'], 'tur' => ['tr', 'Turkish'],
        'ukr' => ['uk', 'Ukrainian'], 'urd' => ['ur', 'Urdu'], 'vie' => ['vi', 'Vietnamese'], 'yid' => ['yi', 'Yiddish'],
        'zho' => ['zh', 'Chinese'],
    ];
    /** Older three-letter codes */
    private const ALIASES = ['ger' => 'deu', 'fre' => 'fra', 'dut' => 'nld', 'gre' => 'ell', 'chi' => 'zho', 'cze' => 'ces', 'per' => 'fas',
        'rum' => 'ron', 'slo' => 'slk', 'wel' => 'cym', 'baq' => 'eus', 'arm' => 'hye', 'geo' => 'kat', 'ice' => 'isl', 'mac' => 'mkd', 'may' => 'msa', 'alb' => 'sqi'];

    /** @return string|null three-letter code for "en", "en-GB", "eng" or "English" */
    public static function toCalibre(string $value): ?string {
        $v = mb_strtolower(trim($value));
        foreach (self::LIST as $code => [$short, $name]) {
            if ($v === $code || $v === mb_strtolower($name)) {
                return $code;
            }
        }
        $base = preg_split('/[-_]/', $v)[0] ?? '';
        $base = self::ALIASES[$base] ?? $base;
        foreach (self::LIST as $code => [$short]) {
            if ($base === $short || $base === $code) {
                return $code;
            }
        }
        // Any other three-letter code is taken as it is; Calibre knows all of them
        return preg_match('/^[a-z]{3}$/', $base) ? $base : null;
    }

    /** Two-letter code as used in the rest of the app, or the given code. */
    public static function fromCalibre(string $code): string {
        $code = strtolower($code);
        return self::LIST[$code][0] ?? $code;
    }

    /** @return list<string> */
    public static function names(): array {
        $names = array_map(static fn (array $l): string => $l[1], array_values(self::LIST));
        sort($names);
        return $names;
    }

    public static function name(string $code): string {
        return self::LIST[strtolower($code)][1] ?? $code;
    }
}
