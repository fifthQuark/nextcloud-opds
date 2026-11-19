<?php

declare(strict_types=1);

namespace OCA\Opds\News;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Metadata\Text;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Downloads article images for a news edition and converts them into
 * something every EPUB reader can show (JPEG, PNG or GIF, at most 1200 px
 * wide). Results, including failures, are cached in the app data folder so
 * that rebuilding an edition does not download everything again.
 *
 * Downloads go through Nextcloud's HTTP client, which refuses local and
 * private network addresses unless the administrator allowed them.
 */
class ImageFetcher {
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_WIDTH = 1200;
    private const KEEP_BYTES = 600 * 1024;
    private const RETRY_FAILED_AFTER = 86400;

    private ?ISimpleFolder $cache = null;
    private float $deadline = 0.0;
    private int $remaining = 0;
    /** Set when the last download failed only because the time budget ran out; such failures are not cached. */
    private bool $outOfTime = false;
    /** True once any image was left out because the time budget ran out. */
    private bool $cut = false;

    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    public function begin(?ISimpleFolder $cache, float $seconds, int $maxImages): void {
        $this->cache = $cache;
        $this->deadline = microtime(true) + $seconds;
        $this->remaining = $maxImages;
        $this->cut = false;
    }

    /** Whether the time budget made the last edition leave out images that could still be fetched later. */
    public function wasCut(): bool {
        return $this->cut;
    }

    /** @return array{data:string,mime:string}|null */
    public function fetch(string $url): ?array {
        if ($this->remaining <= 0) {
            return null;
        }
        $key = sha1($url);
        $cached = $this->fromCache($key);
        if ($cached !== false) {
            if ($cached !== null) {
                $this->remaining--;
            }
            return $cached;
        }
        if (microtime(true) >= $this->deadline) {
            $this->cut = true;
            return null;
        }

        $this->outOfTime = false;
        $raw = str_starts_with($url, 'data:') ? $this->decodeDataUri($url) : $this->download($url);
        $image = $raw !== null ? $this->normalise($raw) : null;
        if ($image !== null || !$this->outOfTime) {
            $this->toCache($key, $image);
        } else {
            $this->cut = true;
        }
        if ($image !== null) {
            $this->remaining--;
        }
        return $image;
    }

    private function download(string $url): ?string {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $timeLeft = $this->deadline - microtime(true);
        if ($timeLeft < 1) {
            $this->outOfTime = true;
            return null;
        }
        try {
            $response = $this->clientService->newClient()->get($url, [
                'timeout' => min(10.0, $timeLeft),
                'connect_timeout' => min(5.0, $timeLeft),
                'stream' => true,
                'headers' => [
                    'User-Agent' => 'OPDS+ for Nextcloud',
                    'Accept' => 'image/avif;q=0,image/webp,image/png,image/jpeg,image/gif,image/*;q=0.8',
                ],
            ]);
            $length = (int)$response->getHeader('Content-Length');
            if ($length > self::MAX_BYTES) {
                return null;
            }
            $body = $response->getBody();
            if (is_string($body)) {
                return strlen($body) <= self::MAX_BYTES ? $body : null;
            }
            $data = '';
            while (!feof($body)) {
                $chunk = fread($body, 65536);
                if ($chunk === false) {
                    break;
                }
                $data .= $chunk;
                if (strlen($data) > self::MAX_BYTES) {
                    fclose($body);
                    return null;
                }
            }
            fclose($body);
            return $data;
        } catch (\Throwable $e) {
            $this->outOfTime = $timeLeft < 10.0;
            $this->logger->debug('OPDS: could not download image ' . $url . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
            return null;
        }
    }

    private function decodeDataUri(string $uri): ?string {
        if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $uri, $m)) {
            return null;
        }
        $payload = substr($uri, strlen($m[0]));
        if (strlen($payload) > self::MAX_BYTES * 4 / 3) {
            return null;
        }
        $data = base64_decode($payload, true);
        return $data !== false ? $data : null;
    }

    /** @return array{data:string,mime:string}|null */
    private function normalise(string $data): ?array {
        $mime = Text::sniffImageMime($data);
        if ($mime === '') {
            return null; // SVG, AVIF, HTML error pages, ...
        }
        if (!function_exists('imagecreatefromstring')) {
            return in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true) && strlen($data) <= self::MAX_BYTES
                ? ['data' => $data, 'mime' => $mime] : null;
        }
        $size = @getimagesizefromstring($data);
        if (is_array($size) && ($size[0] < 8 || $size[1] < 8)) {
            return null; // tracking pixels and spacers
        }
        $width = is_array($size) ? (int)$size[0] : 0;
        $keepAsIs = in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)
            && $width > 0 && $width <= self::MAX_WIDTH && strlen($data) <= self::KEEP_BYTES;
        if ($keepAsIs || ($mime === 'image/gif' && strlen($data) <= self::MAX_BYTES / 2)) {
            return ['data' => $data, 'mime' => $mime];
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return in_array($mime, ['image/jpeg', 'image/png'], true) ? ['data' => $data, 'mime' => $mime] : null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = $w > self::MAX_WIDTH ? self::MAX_WIDTH / $w : 1.0;
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        // Transparent areas become white, like the page behind them
        imagefill($dst, 0, 0, (int)imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, 78);
        $jpeg = (string)ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $jpeg !== '' ? ['data' => $jpeg, 'mime' => 'image/jpeg'] : null;
    }

    /** @return array{data:string,mime:string}|null|false false when not cached */
    private function fromCache(string $key): array|null|false {
        if ($this->cache === null) {
            return false;
        }
        try {
            if ($this->cache->fileExists($key . '.fail')) {
                $failed = $this->cache->getFile($key . '.fail');
                if (time() - $failed->getMTime() < self::RETRY_FAILED_AFTER) {
                    return null;
                }
                $failed->delete();
                return false;
            }
            $file = $this->cache->getFile($key);
            $data = $file->getContent();
            $mime = Text::sniffImageMime($data);
            return $mime !== '' ? ['data' => $data, 'mime' => $mime] : false;
        } catch (NotFoundException $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @param array{data:string,mime:string}|null $image */
    private function toCache(string $key, ?array $image): void {
        if ($this->cache === null) {
            return;
        }
        try {
            if ($image === null) {
                $this->cache->newFile($key . '.fail', '');
            } else {
                $this->cache->newFile($key, $image['data']);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('OPDS: could not cache image: ' . $e->getMessage(), ['app' => Application::APP_ID]);
        }
    }
}
