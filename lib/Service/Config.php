<?php

declare(strict_types=1);

namespace OCA\Opds\Service;

use OCA\Opds\AppInfo\Application;
use OCP\IConfig;

/**
 * Typed access to the per-user settings of the app.
 */
class Config {
    public const DEFAULT_EXTENSIONS = 'epub,kepub,pdf,mobi,azw,azw3,prc,cbz,cbr,cb7,cbt,fb2,djvu,djv,txt,rtf,doc,docx,odt,html,htm,lit,lrf,pdb,txtz,chm';

    /** Sort keys accepted by the catalog, mapped to a human readable label. */
    public const SORTS = [
        'title' => 'Title',
        'author' => 'Author',
        'date' => 'Publication date',
        'added' => 'Date added',
        'modified' => 'Date modified',
        'publisher' => 'Publisher',
        'series' => 'Series',
        'language' => 'Language',
        'format' => 'Format',
        'size' => 'File size',
    ];

    public function __construct(
        private IConfig $config,
    ) {
    }

    public function getRoot(string $uid): string {
        $root = trim($this->config->getUserValue($uid, Application::APP_ID, 'root', '/Books'));
        $root = '/' . trim(str_replace('\\', '/', $root), '/');
        return $root === '/' ? '/' : $root;
    }

    public function getPageSize(string $uid): int {
        $size = (int)$this->config->getUserValue($uid, Application::APP_ID, 'page_size', '50');
        return max(5, min(500, $size));
    }

    public function getDefaultSort(string $uid): string {
        $sort = $this->config->getUserValue($uid, Application::APP_ID, 'default_sort', 'title');
        return array_key_exists($sort, self::SORTS) ? $sort : 'title';
    }

    public function getDefaultOrder(string $uid): string {
        return $this->config->getUserValue($uid, Application::APP_ID, 'default_order', 'asc') === 'desc' ? 'desc' : 'asc';
    }

    /** @return string[] lower-case extensions without the dot */
    public function getExtensions(string $uid): array {
        $raw = $this->config->getUserValue($uid, Application::APP_ID, 'extensions', self::DEFAULT_EXTENSIONS);
        $list = array_filter(array_map(
            static fn (string $e): string => strtolower(trim(trim($e), '.')),
            preg_split('/[\s,;]+/', $raw) ?: []
        ));
        return array_values(array_unique($list));
    }

    /** How a filename without embedded metadata is interpreted: author-title, title-author or title */
    public function getFilenamePattern(string $uid): string {
        $p = $this->config->getUserValue($uid, Application::APP_ID, 'filename_pattern', 'author-title');
        return in_array($p, ['author-title', 'title-author', 'title'], true) ? $p : 'author-title';
    }

    /** Above this many entries an author/series/... list is split into first-letter groups. */
    public function getGroupThreshold(string $uid): int {
        return max(10, (int)$this->config->getUserValue($uid, Application::APP_ID, 'group_threshold', '150'));
    }

    /** Minutes between automatic incremental rescans triggered by catalog requests (0 disables). */
    public function getScanInterval(string $uid): int {
        return max(0, (int)$this->config->getUserValue($uid, Application::APP_ID, 'scan_interval', '30'));
    }

    public function getLastScan(string $uid): int {
        return (int)$this->config->getUserValue($uid, Application::APP_ID, 'last_scan', '0');
    }

    public function setLastScan(string $uid, int $time): void {
        $this->config->setUserValue($uid, Application::APP_ID, 'last_scan', (string)$time);
    }

    public function getLastScanReport(string $uid): string {
        return $this->config->getUserValue($uid, Application::APP_ID, 'last_scan_report', '');
    }

    public function setLastScanReport(string $uid, string $report): void {
        $this->config->setUserValue($uid, Application::APP_ID, 'last_scan_report', $report);
    }

    public function isConfigured(string $uid): bool {
        return $this->config->getUserValue($uid, Application::APP_ID, 'root', '') !== '';
    }

    /** @return array<string,string> */
    public function getAll(string $uid): array {
        return [
            'root' => $this->getRoot($uid),
            'page_size' => (string)$this->getPageSize($uid),
            'default_sort' => $this->getDefaultSort($uid),
            'default_order' => $this->getDefaultOrder($uid),
            'extensions' => implode(',', $this->getExtensions($uid)),
            'filename_pattern' => $this->getFilenamePattern($uid),
            'group_threshold' => (string)$this->getGroupThreshold($uid),
            'scan_interval' => (string)$this->getScanInterval($uid),
        ];
    }

    /** @param array<string,mixed> $values */
    public function save(string $uid, array $values): void {
        $allowed = ['root', 'page_size', 'default_sort', 'default_order', 'extensions', 'filename_pattern', 'group_threshold', 'scan_interval'];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $values) && $values[$key] !== null) {
                $this->config->setUserValue($uid, Application::APP_ID, $key, trim((string)$values[$key]));
            }
        }
    }
}
