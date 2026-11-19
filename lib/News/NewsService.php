<?php

declare(strict_types=1);

namespace OCA\Opds\News;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Service\Config;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Builds the daily news edition: the articles of the selected Nextcloud News
 * feeds within the configured time span, as an EPUB with one part per feed and
 * one chapter per article. Built books are cached until the articles change.
 */
class NewsService {
    /** Bump when the EPUB layout changes so cached editions are rebuilt. */
    private const FORMAT_VERSION = 1;
    /** Image download time while a reader app waits for the book. */
    public const REQUEST_IMAGE_SECONDS = 10.0;
    /** Image download time when the edition is prepared in the background. */
    public const BACKGROUND_IMAGE_SECONDS = 60.0;
    private const MAX_IMAGES = 300;
    private const IMAGE_CACHE_DAYS = 7;

    private ?IAppData $appData = null;

    public function __construct(
        private NewsRepository $repository,
        private Config $config,
        private HtmlCleaner $cleaner,
        private ImageFetcher $images,
        private EpubWriter $writer,
        private IAppDataFactory $appDataFactory,
        private ITempManager $tempManager,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
    }

    /** News is installed for the user and they have not switched the digest off. */
    public function isActive(string $uid): bool {
        return $this->config->isNewsEnabled($uid) && $this->repository->isAvailable($uid);
    }

    public function isNewsInstalled(string $uid): bool {
        return $this->repository->isAvailable($uid);
    }

    /**
     * Folders and feeds for the settings page.
     *
     * @return array{folders: list<array{id:int,name:string,feeds:list<array{id:int,title:string}>}>, feeds: list<array{id:int,title:string}>}
     */
    public function tree(string $uid): array {
        $folders = $this->repository->folders($uid);
        $tree = [];
        foreach ($folders as $id => $folder) {
            $tree[$id] = ['id' => $id, 'name' => $folder['name'], 'feeds' => []];
        }
        $loose = [];
        foreach ($this->repository->feeds($uid) as $feed) {
            $entry = ['id' => $feed['id'], 'title' => $feed['title']];
            if ($feed['folderId'] !== null && isset($tree[$feed['folderId']])) {
                $tree[$feed['folderId']]['feeds'][] = $entry;
            } else {
                $loose[] = $entry;
            }
        }
        return ['folders' => array_values($tree), 'feeds' => $loose];
    }

    /**
     * What the current edition contains, without reading article bodies.
     *
     * @return array{key:string, title:string, subtitle:string, fileName:string, since:int, until:int,
     *               updated:int, total:int, parts:list<array{feed:array<string,mixed>,folder:string,count:int}>}
     */
    public function edition(string $uid): array {
        [$since, $now] = $this->window($uid);
        $folders = $this->repository->folders($uid);
        $unread = $this->config->isNewsUnreadOnly($uid);
        $max = $this->config->getNewsMaxPerFeed($uid);

        $parts = [];
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($this->config->getTimezone($uid));
        // The title carries the date, so a new day means a new book even without new articles
        $fingerprint = [self::FORMAT_VERSION, $this->config->getNews($uid), $this->config->getLanguage($uid), $local->format('Y-m-d e')];
        $updated = 0;
        foreach ($this->selectedFeeds($uid, $folders) as $feed) {
            $items = $this->repository->items($uid, $feed['id'], $since, $unread, $max, false);
            if ($items === []) {
                continue;
            }
            $parts[] = [
                'feed' => $feed,
                'folder' => $feed['folderId'] !== null ? ($folders[$feed['folderId']]['name'] ?? '') : '',
                'count' => count($items),
            ];
            $fingerprint[] = [$feed['id'], $feed['title'], array_map(static fn (array $i): array => [$i['id'], $i['lastModified']], $items)];
            foreach ($items as $item) {
                $updated = max($updated, $item['pubDate']);
            }
        }
        $total = array_sum(array_column($parts, 'count'));

        return [
            'key' => substr(sha1(json_encode($fingerprint, JSON_THROW_ON_ERROR)), 0, 20),
            'title' => 'News – ' . $local->format('l j F Y'),
            'subtitle' => $this->periodLabel($uid, $since, $now),
            'fileName' => 'News ' . $local->format('Y-m-d Hi') . '.epub',
            'date' => $local->format('Y-m-d'),
            'since' => $since,
            'until' => $now,
            'updated' => $updated > 0 ? $updated : $now,
            'total' => $total,
            'parts' => $parts,
        ];
    }

    /**
     * The EPUB of the current edition, built now unless an identical one is cached.
     *
     * @param array<string,mixed>|null $edition result of edition(), computed when null
     */
    public function epub(string $uid, ?array $edition = null, float $imageSeconds = self::REQUEST_IMAGE_SECONDS): ?ISimpleFile {
        $edition ??= $this->edition($uid);
        if ($edition['total'] === 0) {
            return null;
        }
        $folder = $this->folder('news-' . hash('sha256', $uid));
        $complete = $edition['key'] . '.epub';
        // Built while a reader waited, with images left out for lack of time
        $partial = $edition['key'] . '.partial.epub';
        if ($folder->fileExists($complete)) {
            return $folder->getFile($complete);
        }
        $background = $imageSeconds > self::REQUEST_IMAGE_SECONDS;
        if (!$background && $folder->fileExists($partial)) {
            return $folder->getFile($partial);
        }

        $temp = $this->tempManager->getTemporaryFile('.epub');
        if ($temp === false) {
            throw new \RuntimeException('No temporary file available');
        }
        try {
            $this->writer->write($temp, $this->book($uid, $edition, $imageSeconds));
            $name = $this->images->wasCut() ? $partial : $complete;
            if ($folder->fileExists($name)) {
                $folder->getFile($name)->delete();
            }
            $handle = fopen($temp, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Cannot read ' . $temp);
            }
            $file = $folder->newFile($name, $handle);
            if (is_resource($handle)) {
                fclose($handle);
            }
        } finally {
            @unlink($temp);
        }

        // Only the newest edition is kept
        foreach ($folder->getDirectoryListing() as $old) {
            if ($old->getName() !== $name) {
                try {
                    $old->delete();
                } catch (\Throwable $e) {
                    // a parallel request may have removed it already
                }
            }
        }
        return $file;
    }

    /** Removes all cached editions and images of the user. */
    public function clearCache(string $uid): void {
        foreach (['news-', 'newsimg-'] as $prefix) {
            try {
                $this->appData()->getFolder($prefix . hash('sha256', $uid))->delete();
            } catch (\Throwable $e) {
                // nothing cached
            }
        }
    }

    // ------------------------------------------------------------- internals

    /**
     * @param array<string,mixed> $edition
     * @return array<string,mixed> the book structure EpubWriter expects
     */
    private function book(string $uid, array $edition, float $imageSeconds): array {
        $tz = $this->config->getTimezone($uid);
        $withImages = $this->config->isNewsImages($uid);
        $imageFolder = $withImages ? $this->imageCache($uid) : null;
        $this->images->begin($imageFolder, $imageSeconds, self::MAX_IMAGES);

        $images = [];
        $hrefByUrl = [];
        $resolver = !$withImages ? null : function (string $url) use (&$images, &$hrefByUrl): ?string {
            if (array_key_exists($url, $hrefByUrl)) {
                return $hrefByUrl[$url];
            }
            $image = $this->images->fetch($url);
            if ($image === null) {
                return $hrefByUrl[$url] = null;
            }
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'][$image['mime']] ?? 'img';
            $href = sprintf('images/img%04d.%s', count($images) + 1, $ext);
            $images[] = ['href' => $href, 'mime' => $image['mime'], 'data' => $image['data']];
            return $hrefByUrl[$url] = '../' . $href;
        };

        $unread = $this->config->isNewsUnreadOnly($uid);
        $max = $this->config->getNewsMaxPerFeed($uid);
        $parts = [];
        foreach ($edition['parts'] as $partInfo) {
            $feed = $partInfo['feed'];
            $articles = [];
            foreach ($this->repository->items($uid, $feed['id'], $edition['since'], $unread, $max, true) as $item) {
                $body = $item['body'] !== '' ? $item['body'] : nl2br(htmlspecialchars($item['mediaDescription'], ENT_QUOTES, 'UTF-8'));
                $base = $item['url'] !== '' ? $item['url'] : ($feed['link'] !== '' ? $feed['link'] : $feed['url']);
                $html = $this->cleaner->clean($body, $base, $resolver);
                // Feeds that put the picture in an enclosure or media:thumbnail instead of the text
                if ($resolver !== null && !str_contains($html, '<img')) {
                    $lead = str_starts_with($item['enclosureMime'], 'image/') ? $item['enclosureLink'] : $item['thumbnail'];
                    if ($lead !== '' && preg_match('#^https?://#i', $lead)) {
                        $href = $resolver($lead);
                        if ($href !== null) {
                            $html = '<figure><img src="' . htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" alt=""/></figure>' . "\n" . $html;
                        }
                    }
                }
                $articles[] = [
                    'title' => $item['title'],
                    'author' => $item['author'],
                    'url' => $item['url'],
                    'pubDate' => $item['pubDate'],
                    'rtl' => $item['rtl'],
                    'html' => $html,
                ];
            }
            if ($articles !== []) {
                $parts[] = ['title' => $feed['title'], 'folder' => $partInfo['folder'], 'link' => $feed['link'], 'articles' => $articles];
            }
        }

        $hash = sha1($uid . ':' . $edition['key']);
        return [
            'id' => sprintf('urn:uuid:%s-%s-5%s-a%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12)),
            'title' => $edition['title'],
            'subtitle' => $edition['subtitle'],
            'language' => $this->config->getLanguage($uid),
            'date' => new \DateTimeImmutable($edition['date'], $tz),
            'timezone' => $tz,
            'modified' => $this->time->getTime(),
            'parts' => $parts,
            'images' => $images,
        ];
    }

    /**
     * Feeds in the user's selection, in reading order: feeds without a folder
     * first, then folder by folder, alphabetically within each.
     *
     * @param array<int,array{id:int,name:string}> $folders
     * @return list<array{id:int,title:string,folderId:?int,link:string,url:string}>
     */
    private function selectedFeeds(string $uid, array $folders): array {
        $all = $this->config->isNewsAllFeeds($uid);
        $folderIds = array_flip($this->config->getNewsFolders($uid));
        $feedIds = array_flip($this->config->getNewsFeeds($uid));
        $selected = [];
        foreach ($this->repository->feeds($uid) as $feed) {
            if ($all || isset($feedIds[$feed['id']]) || ($feed['folderId'] !== null && isset($folderIds[$feed['folderId']]))) {
                $selected[] = $feed;
            }
        }
        $folderOrder = array_flip(array_keys($folders));
        usort($selected, static function (array $a, array $b) use ($folderOrder): int {
            $fa = $a['folderId'] !== null ? ($folderOrder[$a['folderId']] ?? PHP_INT_MAX) : -1;
            $fb = $b['folderId'] !== null ? ($folderOrder[$b['folderId']] ?? PHP_INT_MAX) : -1;
            return [$fa, mb_strtolower($a['title'])] <=> [$fb, mb_strtolower($b['title'])];
        });
        return $selected;
    }

    /** @return array{0:int,1:int} start and end of the edition as timestamps */
    private function window(string $uid): array {
        $now = $this->time->getTime();
        return [match ($this->config->getNewsPeriod($uid)) {
            'today' => (new \DateTimeImmutable('@' . $now))->setTimezone($this->config->getTimezone($uid))->setTime(0, 0)->getTimestamp(),
            '48h' => $now - 2 * 86400,
            '7d' => $now - 7 * 86400,
            default => $now - 86400,
        }, $now];
    }

    private function periodLabel(string $uid, int $since, int $now): string {
        $tz = $this->config->getTimezone($uid);
        $from = (new \DateTimeImmutable('@' . $since))->setTimezone($tz);
        $to = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $sameDay = $from->format('Y-m-d') === $to->format('Y-m-d');
        return $sameDay
            ? $to->format('l j F Y') . ', ' . $from->format('H:i') . '–' . $to->format('H:i')
            : $from->format('D j M, H:i') . ' – ' . $to->format('D j M Y, H:i');
    }

    private function imageCache(string $uid): ?ISimpleFolder {
        try {
            $folder = $this->folder('newsimg-' . hash('sha256', $uid));
            // Drop images that no recent edition used, at most once a day
            $last = (int)$this->config->getUserValueRaw($uid, 'news_img_cleanup');
            $now = $this->time->getTime();
            if ($now - $last > 86400) {
                $this->config->setUserValueRaw($uid, 'news_img_cleanup', (string)$now);
                foreach ($folder->getDirectoryListing() as $file) {
                    if ($now - $file->getMTime() > self::IMAGE_CACHE_DAYS * 86400) {
                        $file->delete();
                    }
                }
            }
            return $folder;
        } catch (\Throwable $e) {
            $this->logger->info('OPDS: image cache unavailable: ' . $e->getMessage(), ['app' => Application::APP_ID]);
            return null;
        }
    }

    private function folder(string $name): ISimpleFolder {
        try {
            return $this->appData()->getFolder($name);
        } catch (NotFoundException $e) {
            return $this->appData()->newFolder($name);
        }
    }

    private function appData(): IAppData {
        return $this->appData ??= $this->appDataFactory->get(Application::APP_ID);
    }
}
