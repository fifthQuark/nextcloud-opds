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

    // ------------------------------------------------------------ news digest

    /** Time spans a news edition can cover, mapped to a label. */
    public const NEWS_PERIODS = [
        '24h' => 'Last 24 hours',
        'today' => 'Today (since midnight)',
        '48h' => 'Last 48 hours',
        '7d' => 'Last 7 days',
    ];

    public function isNewsEnabled(string $uid): bool {
        return $this->config->getUserValue($uid, Application::APP_ID, 'news_enabled', '1') === '1';
    }

    /** True when every feed is included (the default); false when only the selection below is. */
    public function isNewsAllFeeds(string $uid): bool {
        return $this->config->getUserValue($uid, Application::APP_ID, 'news_all', '1') === '1';
    }

    /** @return int[] */
    public function getNewsFolders(string $uid): array {
        return $this->idList($this->config->getUserValue($uid, Application::APP_ID, 'news_folders', ''));
    }

    /** @return int[] */
    public function getNewsFeeds(string $uid): array {
        return $this->idList($this->config->getUserValue($uid, Application::APP_ID, 'news_feeds', ''));
    }

    public function getNewsPeriod(string $uid): string {
        $p = $this->config->getUserValue($uid, Application::APP_ID, 'news_period', '24h');
        return array_key_exists($p, self::NEWS_PERIODS) ? $p : '24h';
    }

    public function isNewsUnreadOnly(string $uid): bool {
        return $this->config->getUserValue($uid, Application::APP_ID, 'news_unread', '0') === '1';
    }

    public function getNewsMaxPerFeed(string $uid): int {
        return max(1, min(500, (int)$this->config->getUserValue($uid, Application::APP_ID, 'news_max', '30')));
    }

    public function isNewsImages(string $uid): bool {
        return $this->config->getUserValue($uid, Application::APP_ID, 'news_images', '1') === '1';
    }

    /** @return array<string,mixed> */
    public function getNews(string $uid): array {
        return [
            'enabled' => $this->isNewsEnabled($uid),
            'all' => $this->isNewsAllFeeds($uid),
            'folders' => $this->getNewsFolders($uid),
            'feeds' => $this->getNewsFeeds($uid),
            'period' => $this->getNewsPeriod($uid),
            'unread' => $this->isNewsUnreadOnly($uid),
            'max' => $this->getNewsMaxPerFeed($uid),
            'images' => $this->isNewsImages($uid),
        ];
    }

    /** @param array<string,mixed> $values keys as returned by getNews() */
    public function saveNews(string $uid, array $values): void {
        $bool = static fn ($v): string => ($v === true || $v === '1' || $v === 1 || $v === 'true') ? '1' : '0';
        $map = [
            'enabled' => ['news_enabled', $bool],
            'all' => ['news_all', $bool],
            'unread' => ['news_unread', $bool],
            'images' => ['news_images', $bool],
            'folders' => ['news_folders', fn ($v): string => implode(',', $this->idList($v))],
            'feeds' => ['news_feeds', fn ($v): string => implode(',', $this->idList($v))],
            'period' => ['news_period', static fn ($v): string => array_key_exists((string)$v, self::NEWS_PERIODS) ? (string)$v : '24h'],
            'max' => ['news_max', static fn ($v): string => (string)max(1, min(500, (int)$v))],
        ];
        foreach ($map as $key => [$configKey, $normalise]) {
            if (array_key_exists($key, $values) && $values[$key] !== null) {
                $this->config->setUserValue($uid, Application::APP_ID, $configKey, $normalise($values[$key]));
            }
        }
    }

    /** @return int[] */
    private function idList(mixed $raw): array {
        $parts = is_array($raw) ? $raw : (preg_split('/[\s,]+/', (string)$raw) ?: []);
        $ids = array_filter(array_map('intval', $parts), static fn (int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }

    /** Internal bookkeeping values that are not user settings. */
    public function getUserValueRaw(string $uid, string $key): string {
        return $this->config->getUserValue($uid, Application::APP_ID, $key, '');
    }

    public function setUserValueRaw(string $uid, string $key, string $value): void {
        $this->config->setUserValue($uid, Application::APP_ID, $key, $value);
    }

    public function getTimezone(string $uid): \DateTimeZone {
        $name = $this->config->getUserValue($uid, 'core', 'timezone', '')
            ?: (string)$this->config->getSystemValue('default_timezone', 'UTC');
        try {
            return new \DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    public function getLanguage(string $uid): string {
        $lang = $this->config->getUserValue($uid, 'core', 'lang', '')
            ?: (string)$this->config->getSystemValue('default_language', 'en');
        return str_replace('_', '-', $lang !== '' ? $lang : 'en');
    }

    /** Beta: use and edit the metadata of Calibre libraries. */
    public function isCalibreEnabled(string $uid): bool {
        return $this->config->getUserValue($uid, Application::APP_ID, 'calibre_beta', '0') === '1';
    }

    public function setCalibreEnabled(string $uid, bool $enabled): void {
        $this->config->setUserValue($uid, Application::APP_ID, 'calibre_beta', $enabled ? '1' : '0');
    }

    public function setRoot(string $uid, string $root): void {
        $this->config->setUserValue($uid, Application::APP_ID, 'root', $root);
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
