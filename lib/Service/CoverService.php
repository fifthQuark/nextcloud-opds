<?php

declare(strict_types=1);

namespace OCA\Opds\Service;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Metadata\Text;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Stores extracted cover images (full size and thumbnail) in the app data
 * folder, one sub folder per user.
 */
class CoverService {
    private const COVER_MAX = [1000, 1500];
    private const THUMB_MAX = [320, 480];

    private ?IAppData $appData = null;

    public function __construct(
        private IAppDataFactory $appDataFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function store(string $uid, int $fileId, string $data): bool {
        if (Text::sniffImageMime($data) === '') {
            return false;
        }
        try {
            $folder = $this->userFolder($uid, true);
            $this->write($folder, $fileId . '.cover', $this->resize($data, self::COVER_MAX));
            $this->write($folder, $fileId . '.thumb', $this->resize($data, self::THUMB_MAX));
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('OPDS: could not store cover for file ' . $fileId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
            return false;
        }
    }

    public function get(string $uid, int $fileId, bool $thumbnail): ?ISimpleFile {
        try {
            $folder = $this->userFolder($uid, false);
            if ($folder === null) {
                return null;
            }
            return $folder->getFile($fileId . ($thumbnail ? '.thumb' : '.cover'));
        } catch (NotFoundException $e) {
            return null;
        }
    }

    public function delete(string $uid, int $fileId): void {
        try {
            $folder = $this->userFolder($uid, false);
            if ($folder === null) {
                return;
            }
            foreach ([$fileId . '.cover', $fileId . '.thumb'] as $name) {
                if ($folder->fileExists($name)) {
                    $folder->getFile($name)->delete();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('OPDS: could not delete cover: ' . $e->getMessage(), ['app' => Application::APP_ID]);
        }
    }

    public function deleteAll(string $uid): void {
        try {
            $folder = $this->userFolder($uid, false);
            $folder?->delete();
        } catch (\Throwable $e) {
            $this->logger->debug('OPDS: could not delete covers: ' . $e->getMessage(), ['app' => Application::APP_ID]);
        }
    }

    private function write(ISimpleFolder $folder, string $name, string $data): void {
        if ($folder->fileExists($name)) {
            $folder->getFile($name)->putContent($data);
        } else {
            $folder->newFile($name, $data);
        }
    }

    /** @param array{0:int,1:int} $max */
    private function resize(string $data, array $max): string {
        try {
            $image = new \OCP\Image();
            $image->loadFromData($data);
            if (!$image->valid()) {
                return $data;
            }
            $image->fixOrientation();
            if ($image->width() > $max[0] || $image->height() > $max[1]) {
                $image->scaleDownToFit($max[0], $max[1]);
            } elseif ($max === self::COVER_MAX) {
                // Small enough already: keep the original bytes untouched
                return $data;
            }
            $out = $image->data();
            return ($out !== null && $out !== '') ? $out : $data;
        } catch (\Throwable $e) {
            return $data;
        }
    }

    private function userFolder(string $uid, bool $create): ?ISimpleFolder {
        $this->appData ??= $this->appDataFactory->get(Application::APP_ID);
        $name = 'covers-' . hash('sha256', $uid);
        try {
            return $this->appData->getFolder($name);
        } catch (NotFoundException $e) {
            return $create ? $this->appData->newFolder($name) : null;
        }
    }
}
