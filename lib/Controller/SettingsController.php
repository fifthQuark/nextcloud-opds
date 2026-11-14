<?php

declare(strict_types=1);

namespace OCA\Opds\Controller;

use OCA\Opds\AppInfo\Application;
use OCA\Opds\Service\Config;
use OCA\Opds\Service\LibraryScanner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Backend of the personal settings page (regular session + CSRF protected).
 */
class SettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private Config $config,
        private LibraryScanner $scanner,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function save(
        string $root = '', string $page_size = '', string $default_sort = '', string $default_order = '',
        string $extensions = '', string $filename_pattern = '', string $group_threshold = '', string $scan_interval = '',
    ): JSONResponse {
        $uid = $this->uid();
        $root = '/' . trim(str_replace('\\', '/', $root), '/');
        try {
            $node = $this->rootFolder->getUserFolder($uid)->get($root);
            if (!$node instanceof Folder) {
                return new JSONResponse(['error' => $root . ' is not a folder'], Http::STATUS_BAD_REQUEST);
            }
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Folder ' . $root . ' does not exist'], Http::STATUS_BAD_REQUEST);
        }

        $before = $this->config->getAll($uid);
        $this->config->save($uid, [
            'root' => $root,
            'page_size' => $page_size,
            'default_sort' => $default_sort,
            'default_order' => $default_order,
            'extensions' => $extensions,
            'filename_pattern' => $filename_pattern,
            'group_threshold' => $group_threshold,
            'scan_interval' => $scan_interval,
        ]);
        $after = $this->config->getAll($uid);

        // A different folder, extension list or filename rule changes what is indexed
        if ($before['root'] !== $after['root'] || $before['extensions'] !== $after['extensions'] || $before['filename_pattern'] !== $after['filename_pattern']) {
            $this->scanner->reset($uid);
        }
        return new JSONResponse(['settings' => $after]);
    }

    #[NoAdminRequired]
    public function scan(bool $full = false): JSONResponse {
        $uid = $this->uid();
        $result = $this->scanner->scan($uid, 50, $full);
        return new JSONResponse([
            'ok' => $result->error === '',
            'summary' => $result->summary(),
            'result' => json_decode($result->toJson(), true),
        ], $result->error === '' ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST);
    }

    #[NoAdminRequired]
    public function reset(): JSONResponse {
        $uid = $this->uid();
        $this->scanner->reset($uid);
        return new JSONResponse(['ok' => true]);
    }

    private function uid(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('Not logged in');
        }
        return $user->getUID();
    }
}
