<?php

declare(strict_types=1);

namespace OCA\Opds\News;

use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Read-only access to the data of the Nextcloud News app.
 *
 * The News tables are read directly instead of going through News' PHP
 * services, which are internal and change between releases. Only columns that
 * have been stable for many News versions are used.
 */
class NewsRepository {
    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IUserManager $userManager,
    ) {
    }

    /** True when the News app is installed, enabled for this user and its tables exist. */
    public function isAvailable(string $uid): bool {
        try {
            $user = $this->userManager->get($uid);
            if ($user === null || !$this->appManager->isEnabledForUser('news', $user)) {
                return false;
            }
            return $this->db->tableExists('news_items') && $this->db->tableExists('news_feeds') && $this->db->tableExists('news_folders');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<int,array{id:int,name:string}> keyed by id, sorted by name */
    public function folders(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name')
            ->from('news_folders')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($this->notDeleted($qb));
        $folders = [];
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $folders[(int)$row['id']] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
        $result->closeCursor();
        uasort($folders, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $folders;
    }

    /**
     * @return array<int,array{id:int,title:string,folderId:?int,link:string,url:string}>
     *         keyed by id, sorted by title
     */
    public function feeds(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'title', 'folder_id', 'link', 'url')
            ->from('news_feeds')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($this->notDeleted($qb));
        $feeds = [];
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $folder = $row['folder_id'] !== null ? (int)$row['folder_id'] : null;
            $feeds[(int)$row['id']] = [
                'id' => (int)$row['id'],
                'title' => trim((string)$row['title']) !== '' ? trim((string)$row['title']) : (string)$row['url'],
                'folderId' => $folder !== null && $folder > 0 ? $folder : null,
                'link' => (string)($row['link'] ?? ''),
                'url' => (string)($row['url'] ?? ''),
            ];
        }
        $result->closeCursor();
        uasort($feeds, static fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
        return $feeds;
    }

    /**
     * Newest articles of one feed published at or after $since.
     *
     * @return list<array<string,mixed>>
     */
    public function items(string $uid, int $feedId, int $since, bool $unreadOnly, int $limit, bool $withBody): array {
        $qb = $this->db->getQueryBuilder();
        $columns = ['i.id', 'i.title', 'i.author', 'i.url', 'i.pub_date', 'i.last_modified', 'i.rtl', 'i.unread', 'i.enclosure_mime', 'i.enclosure_link'];
        if ($withBody) {
            $columns[] = 'i.body';
            $columns[] = 'i.media_thumbnail';
            $columns[] = 'i.media_description';
        }
        $qb->select(...$columns)
            ->from('news_items', 'i')
            ->innerJoin('i', 'news_feeds', 'f', $qb->expr()->eq('i.feed_id', 'f.id'))
            ->where($qb->expr()->eq('f.user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('i.feed_id', $qb->createNamedParameter($feedId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('i.pub_date', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
            ->orderBy('i.pub_date', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit);
        if ($unreadOnly) {
            $qb->andWhere($qb->expr()->eq('i.unread', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
        $items = [];
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $items[] = [
                'id' => (int)$row['id'],
                'title' => (string)($row['title'] ?? ''),
                'author' => (string)($row['author'] ?? ''),
                'url' => (string)($row['url'] ?? ''),
                'pubDate' => (int)($row['pub_date'] ?? 0),
                'lastModified' => (string)($row['last_modified'] ?? ''),
                'rtl' => (bool)($row['rtl'] ?? false),
                'enclosureMime' => (string)($row['enclosure_mime'] ?? ''),
                'enclosureLink' => (string)($row['enclosure_link'] ?? ''),
                'body' => $withBody ? (string)($row['body'] ?? '') : '',
                'thumbnail' => $withBody ? (string)($row['media_thumbnail'] ?? '') : '',
                'mediaDescription' => $withBody ? (string)($row['media_description'] ?? '') : '',
            ];
        }
        $result->closeCursor();
        return $items;
    }

    private function notDeleted(IQueryBuilder $qb): \OCP\DB\QueryBuilder\ICompositeExpression {
        return $qb->expr()->orX(
            $qb->expr()->isNull('deleted_at'),
            $qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
        );
    }
}
