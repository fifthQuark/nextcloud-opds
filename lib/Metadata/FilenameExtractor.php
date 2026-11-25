<?php

declare(strict_types=1);

namespace OCA\Opds\Metadata;

/**
 * Derives title/author from the file name when a file carries no metadata.
 */
class FilenameExtractor {
    /** Extensions that are a pair, e.g. book.kepub.epub */
    private const COMPOUND = ['kepub.epub', 'fb2.zip', 'tar.gz'];

    public function extract(string $fileName, string $pattern): Metadata {
        $meta = new Metadata();
        $base = self::stripExtension($fileName);
        $base = str_replace(['_', '.'], ' ', $base);
        $base = preg_replace('/\s+/u', ' ', $base) ?? $base;
        $base = trim($base);

        // Trailing "(2019)" or "[2019]" is a year
        if (preg_match('/^(.*?)\s*[\(\[]\s*((?:1[5-9]|20)\d{2})\s*[\)\]]\s*$/u', $base, $m)) {
            $base = trim($m[1]);
            $meta->date = $m[2];
        }

        $parts = preg_split('/\s+-\s+|\s+[\x{2013}\x{2014}]\s+/u', $base, 2) ?: [$base];
        if ($pattern !== 'title' && count($parts) === 2) {
            [$a, $b] = array_map('trim', $parts);
            if ($pattern === 'author-title') {
                $meta->addAuthor($a);
                $meta->title = $b;
            } else {
                $meta->title = $a;
                $meta->addAuthor($b);
            }
        } else {
            $meta->title = $base;
        }
        if ($meta->title === '') {
            $meta->title = $fileName;
        }
        return $meta;
    }

    public static function stripExtension(string $fileName): string {
        $lower = strtolower($fileName);
        foreach (self::COMPOUND as $ext) {
            if (str_ends_with($lower, '.' . $ext)) {
                return substr($fileName, 0, -strlen($ext) - 1);
            }
        }
        $pos = strrpos($fileName, '.');
        return $pos === false || $pos === 0 ? $fileName : substr($fileName, 0, $pos);
    }

    /** @param string $path inside the library folder; files in a hidden folder are hidden as well */
    public static function isHidden(string $path): bool {
        return str_contains('/' . $path, '/.');
    }

    /** Lower-case extension, honouring compound ones ("kepub.epub" -> "epub", "fb2.zip" -> "fb2"). */
    public static function format(string $fileName): string {
        $lower = strtolower($fileName);
        if (str_ends_with($lower, '.fb2.zip')) {
            return 'fb2';
        }
        $ext = pathinfo($lower, PATHINFO_EXTENSION);
        return is_string($ext) ? $ext : '';
    }
}
