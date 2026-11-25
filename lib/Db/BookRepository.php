<?php

declare(strict_types=1);

namespace OCA\Opds\Db;

use OCA\Opds\Metadata\Metadata;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Database access for indexed books. Uses the query builder directly so that
 * sorting, filtering and grouping can be expressed freely.
 */
class BookRepository {
    public const TABLE = 'opds_books';
    public const TERMS = 'opds_terms';

    public const AUTHOR_SEPARATOR = ' & ';
    public const SUBJECT_SEPARATOR = '; ';

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    // ---------------------------------------------------------------- reading

    public function findByFileId(string $uid, int $fileId): ?Book {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? $this->hydrate($row) : null;
    }

    /** @return array<int,string> file id => etag of every indexed book of the user */
    public function getEtags(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'etag')->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['file_id']] = (string)$row['etag'];
        }
        $result->closeCursor();
        return $map;
    }

    public function count(string $uid): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'cnt'))->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * @return array{books: Book[], total: int}
     */
    public function listBooks(string $uid, BookFilter $filter, string $sort, string $order, int $limit, int $offset): array {
        $countQb = $this->db->getQueryBuilder();
        $countQb->select($countQb->func()->count('b.id', 'cnt'))->from(self::TABLE, 'b');
        $this->applyFilter($countQb, $uid, $filter);
        $result = $countQb->executeQuery();
        $total = (int)$result->fetchOne();
        $result->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $qb->select('b.*')->from(self::TABLE, 'b');
        $this->applyFilter($qb, $uid, $filter);
        $this->applySort($qb, $sort, $order);
        $qb->setMaxResults($limit)->setFirstResult($offset);

        $books = [];
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $books[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return ['books' => $books, 'total' => $total];
    }

    /**
     * Distinct values of a multi-valued term (author, subject) with book counts.
     *
     * @return list<array{value: string, sort: string, count: int}>
     */
    public function listTerms(string $uid, string $kind, ?string $letter = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('value', 'value_sort')
            ->selectAlias($qb->func()->count('id'), 'cnt')
            ->from(self::TERMS)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
            ->groupBy('value', 'value_sort')
            ->orderBy('value_sort', 'ASC');
        $this->applyLetter($qb, 'value_sort', $letter);

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = ['value' => (string)$row['value'], 'sort' => (string)$row['value_sort'], 'count' => (int)$row['cnt']];
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Series of one author with the number of the author's books in each, and the
     * number of the author's books that belong to no series.
     *
     * @return array{series: list<array{value: string, sort: string, count: int}>, standalone: int, total: int}
     */
    public function listAuthorSeries(string $uid, string $author): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('b.series')
            ->selectAlias($qb->func()->count('b.id'), 'cnt')
            ->from(self::TABLE, 'b');
        $filter = new BookFilter();
        $filter->author = $author;
        $this->applyFilter($qb, $uid, $filter);
        $qb->groupBy('b.series');

        $out = ['series' => [], 'standalone' => 0, 'total' => 0];
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $name = (string)$row['series'];
            $count = (int)$row['cnt'];
            $out['total'] += $count;
            if ($name === '') {
                $out['standalone'] += $count;
            } else {
                $out['series'][] = ['value' => $name, 'sort' => Sorting::titleSortKey($name), 'count' => $count];
            }
        }
        $result->closeCursor();
        usort($out['series'], static fn (array $a, array $b): int => [$a['sort'], $a['value']] <=> [$b['sort'], $b['value']]);
        return $out;
    }

    /** @return array<string,int> number of series per author; authors without a series are absent */
    public function countSeriesByAuthor(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct(['t.value', 'b.series'])
            ->from(self::TERMS, 't')
            ->innerJoin('t', self::TABLE, 'b', $qb->expr()->eq('b.id', 't.book_id'))
            ->where($qb->expr()->eq('t.user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('t.kind', $qb->createNamedParameter('author')))
            ->andWhere($qb->expr()->nonEmptyString('b.series'));
        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $author = (string)$row['value'];
            $counts[$author] = ($counts[$author] ?? 0) + 1;
        }
        $result->closeCursor();
        return $counts;
    }

    /**
     * Distinct values of a single-valued column (publisher, series, language, format)
     * with book counts.
     *
     * @return list<array{value: string, sort: string, count: int}>
     */
    public function listColumn(string $uid, string $column, ?string $letter = null, string $direction = 'ASC'): array {
        if (!in_array($column, ['publisher', 'series', 'language', 'format', 'pubyear', 'title_letter'], true)) {
            throw new \InvalidArgumentException('Unsupported column ' . $column);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select($column)
            ->selectAlias($qb->func()->count('id'), 'cnt')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->groupBy($column)
            ->orderBy($column, $direction);
        if ($column === 'pubyear') {
            $qb->andWhere($qb->expr()->gt($column, $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        } elseif ($column !== 'title_letter') {
            $qb->andWhere($qb->expr()->neq($column, $qb->createNamedParameter('')));
        }
        if ($column === 'publisher' || $column === 'series') {
            $qb->orderBy($qb->func()->lower($column), $direction);
            $this->applyLetter($qb, $column, $letter);
        }

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $value = (string)$row[$column];
            $rows[] = ['value' => $value, 'sort' => mb_strtolower($value), 'count' => (int)$row['cnt']];
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Immediate sub folders of $parent that contain (directly or deeper) at
     * least one book.
     *
     * @return list<array{name: string, path: string, count: int}>
     */
    public function listSubfolders(string $uid, string $parent): array {
        $parent = rtrim($parent, '/');
        $qb = $this->db->getQueryBuilder();
        $qb->select('parent')
            ->selectAlias($qb->func()->count('id'), 'cnt')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->like('parent', $qb->createNamedParameter($this->db->escapeLikeParameter($parent) . '/%')))
            ->groupBy('parent');
        $result = $qb->executeQuery();
        $children = [];
        $prefixLen = strlen($parent) + 1;
        while ($row = $result->fetch()) {
            $rest = substr((string)$row['parent'], $prefixLen);
            $name = explode('/', $rest, 2)[0];
            if ($name === '') {
                continue;
            }
            if (!isset($children[$name])) {
                $children[$name] = ['name' => $name, 'path' => $parent . '/' . $name, 'count' => 0];
            }
            $children[$name]['count'] += (int)$row['cnt'];
        }
        $result->closeCursor();
        uksort($children, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
        return array_values($children);
    }

    // ---------------------------------------------------------------- writing

    public function save(Book $book): void {
        $this->db->beginTransaction();
        try {
            $values = $this->toRow($book);
            if ($book->id > 0) {
                $qb = $this->db->getQueryBuilder();
                $qb->update(self::TABLE);
                foreach ($values as $column => [$value, $type]) {
                    $qb->set($column, $qb->createNamedParameter($value, $type));
                }
                $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($book->id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } else {
                $qb = $this->db->getQueryBuilder();
                $qb->insert(self::TABLE);
                foreach ($values as $column => [$value, $type]) {
                    $qb->setValue($column, $qb->createNamedParameter($value, $type));
                }
                $qb->executeStatement();
                $book->id = $qb->getLastInsertId();
            }

            $this->replaceTerms($book);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Separates combined author fields ("A & B", "A; B") of books indexed by versions
     * that stored them as one person. Returns the number of changed books.
     */
    public function splitStoredAuthors(): int {
        $changed = 0;
        $lastId = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')->from(self::TABLE)
                ->where($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->nonEmptyString('author'))
                ->orderBy('id', 'ASC')
                ->setMaxResults(500);
            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $book = $this->hydrate($row);
                $meta = new Metadata();
                $sorts = explode(self::AUTHOR_SEPARATOR, $book->authorSort);
                foreach ($book->authors as $i => $author) {
                    // A sort name made from a combined field ("gaiman, terry pratchett & neil")
                    // does not consist of the same words as the author it would belong to
                    $sort = $sorts[$i] ?? '';
                    $meta->addAuthor($author, Sorting::sameWords($author, $sort) ? $sort : '');
                }
                // A joined field reads back as a list here, but is still a single term
                if (count($meta->authors) < 2 && count($book->authors) < 2) {
                    continue;
                }
                $book->authors = $meta->authors;
                $book->authorSort = Sorting::authorSortKey($meta->authors, $meta->authorsSort);
                $this->save($book);
                $changed++;
            }
        } while (count($rows) === 500);
        return $changed;
    }

    /** @param int[] $fileIds */
    public function deleteByFileIds(string $uid, array $fileIds): void {
        foreach (array_chunk($fileIds, 200) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')->from(self::TABLE)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            $ids = array_map('intval', array_column($result->fetchAll(), 'id'));
            $result->closeCursor();
            if ($ids === []) {
                continue;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::TERMS)
                ->where($qb->expr()->in('book_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();

            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::TABLE)
                ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();
        }
    }

    public function deleteAll(string $uid): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TERMS)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $qb->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $qb->executeStatement();
    }

    // -------------------------------------------------------------- internals

    private function replaceTerms(Book $book): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TERMS)
            ->where($qb->expr()->eq('book_id', $qb->createNamedParameter($book->id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();

        $terms = [];
        foreach ($book->authors as $author) {
            $terms['author'][] = $author;
        }
        foreach ($book->subjects as $subject) {
            $terms['subject'][] = $subject;
        }
        foreach ($terms as $kind => $values) {
            $seen = [];
            foreach ($values as $value) {
                $value = self::truncate(trim($value), 255);
                $key = mb_strtolower($value);
                if ($value === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $sort = $kind === 'author' ? Sorting::personSortKey($value) : Sorting::sortKey($value);
                $qb = $this->db->getQueryBuilder();
                $qb->insert(self::TERMS)
                    ->setValue('book_id', $qb->createNamedParameter($book->id, IQueryBuilder::PARAM_INT))
                    ->setValue('user_id', $qb->createNamedParameter($book->userId))
                    ->setValue('kind', $qb->createNamedParameter($kind))
                    ->setValue('value', $qb->createNamedParameter($value))
                    ->setValue('value_sort', $qb->createNamedParameter(self::truncate($sort, 255)));
                $qb->executeStatement();
            }
        }
    }

    private function applyFilter(IQueryBuilder $qb, string $uid, BookFilter $f): void {
        $qb->where($qb->expr()->eq('b.user_id', $qb->createNamedParameter($uid)));

        $termJoins = ['author' => $f->author, 'subject' => $f->subject];
        $i = 0;
        foreach ($termJoins as $kind => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $alias = 't' . (++$i);
            $qb->innerJoin('b', self::TERMS, $alias, $qb->expr()->andX(
                $qb->expr()->eq($alias . '.book_id', 'b.id'),
                $qb->expr()->eq($alias . '.kind', $qb->createNamedParameter($kind)),
                $qb->expr()->eq($alias . '.value', $qb->createNamedParameter(self::truncate($value, 255)))
            ));
        }

        foreach (['publisher' => $f->publisher, 'series' => $f->series, 'language' => $f->language, 'format' => $f->format] as $column => $value) {
            if ($value !== null && $value !== '') {
                $qb->andWhere($qb->expr()->eq('b.' . $column, $qb->createNamedParameter($value)));
            }
        }
        if ($f->standalone === true) {
            $qb->andWhere($qb->expr()->emptyString('b.series'));
        }
        if ($f->year !== null) {
            $qb->andWhere($qb->expr()->eq('b.pubyear', $qb->createNamedParameter($f->year, IQueryBuilder::PARAM_INT)));
        }
        if ($f->letter !== null && $f->letter !== '') {
            $qb->andWhere($qb->expr()->eq('b.title_letter', $qb->createNamedParameter(Sorting::letterOf($f->letter))));
        }
        if ($f->folder !== null && $f->folder !== '') {
            $qb->andWhere($qb->expr()->eq('b.parent', $qb->createNamedParameter(rtrim($f->folder, '/') ?: '/')));
        }
        if ($f->query !== null && trim($f->query) !== '') {
            $words = preg_split('/\s+/', trim($f->query)) ?: [];
            foreach ($words as $word) {
                $like = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter(mb_strtolower($word)) . '%');
                $qb->andWhere($qb->expr()->orX(
                    $qb->expr()->iLike('b.title', $like),
                    $qb->expr()->iLike('b.author', $like),
                    $qb->expr()->iLike('b.subjects', $like),
                    $qb->expr()->iLike('b.series', $like),
                    $qb->expr()->iLike('b.publisher', $like),
                    $qb->expr()->iLike('b.name', $like)
                ));
            }
        }
    }

    private function applySort(IQueryBuilder $qb, string $sort, string $order): void {
        $dir = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $columns = match ($sort) {
            'author' => ['author_sort', 'series', 'series_index', 'title_sort'],
            'date' => ['pubdate', 'title_sort'],
            'added' => ['indexed_at', 'title_sort'],
            'modified' => ['mtime', 'title_sort'],
            'publisher' => ['publisher', 'title_sort'],
            'series' => ['series', 'series_index', 'title_sort'],
            'language' => ['language', 'title_sort'],
            'format' => ['format', 'title_sort'],
            'size' => ['size', 'title_sort'],
            default => ['title_sort', 'author_sort'],
        };
        foreach ($columns as $i => $column) {
            // Secondary keys keep a natural ascending order regardless of direction,
            // except where they belong to the primary key (series index).
            $colDir = ($i === 0 || ($sort === 'series' && $column === 'series_index') || ($sort === 'author' && $column !== 'title_sort')) ? $dir : 'ASC';
            $qb->addOrderBy('b.' . $column, $colDir);
        }
        $qb->addOrderBy('b.id', 'ASC');
    }

    private function applyLetter(IQueryBuilder $qb, string $column, ?string $letter): void {
        if ($letter === null || $letter === '') {
            return;
        }
        $letter = Sorting::letterOf($letter);
        $lower = $qb->func()->lower($column);
        if ($letter === '#') {
            // Everything that does not start with a latin letter
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->lt($lower, $qb->createNamedParameter('a')),
                $qb->expr()->gt($lower, $qb->createNamedParameter('zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'))
            ));
        } else {
            $qb->andWhere($qb->expr()->like($lower, $qb->createNamedParameter(strtolower($letter) . '%')));
        }
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Book {
        $b = new Book();
        $b->id = (int)$row['id'];
        $b->userId = (string)$row['user_id'];
        $b->fileId = (int)$row['file_id'];
        $b->path = (string)$row['path'];
        $b->parent = (string)$row['parent'];
        $b->name = (string)$row['name'];
        $b->etag = (string)$row['etag'];
        $b->mtime = (int)$row['mtime'];
        $b->size = (int)$row['size'];
        $b->mimetype = (string)$row['mimetype'];
        $b->format = (string)$row['format'];
        $b->title = (string)$row['title'];
        $b->titleSort = (string)$row['title_sort'];
        $b->titleLetter = (string)$row['title_letter'];
        $b->authors = $row['author'] !== '' ? explode(self::AUTHOR_SEPARATOR, (string)$row['author']) : [];
        $b->authorSort = (string)$row['author_sort'];
        $b->publisher = (string)$row['publisher'];
        $b->series = (string)$row['series'];
        $b->seriesIndex = (float)$row['series_index'];
        $b->subjects = $row['subjects'] !== '' ? explode(self::SUBJECT_SEPARATOR, (string)$row['subjects']) : [];
        $b->language = (string)$row['language'];
        $b->pubdate = (string)$row['pubdate'];
        $b->pubyear = (int)$row['pubyear'];
        $b->description = (string)($row['description'] ?? '');
        $b->isbn = (string)$row['isbn'];
        $b->hasCover = (int)$row['has_cover'] === 1;
        $b->indexedAt = (int)$row['indexed_at'];
        return $b;
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    private function toRow(Book $b): array {
        $s = IQueryBuilder::PARAM_STR;
        $i = IQueryBuilder::PARAM_INT;
        return [
            'user_id' => [$b->userId, $s],
            'file_id' => [$b->fileId, $i],
            'path' => [self::truncate($b->path, 4000), $s],
            'parent' => [self::truncate($b->parent, 4000), $s],
            'name' => [self::truncate($b->name, 255), $s],
            'etag' => [self::truncate($b->etag, 40), $s],
            'mtime' => [$b->mtime, $i],
            'size' => [$b->size, $i],
            'mimetype' => [self::truncate($b->mimetype, 255), $s],
            'format' => [self::truncate($b->format, 16), $s],
            'title' => [self::truncate($b->title, 1000), $s],
            'title_sort' => [self::truncate($b->titleSort, 255), $s],
            'title_letter' => [substr($b->titleLetter, 0, 1) ?: '#', $s],
            'author' => [self::truncate(implode(self::AUTHOR_SEPARATOR, $b->authors), 1000), $s],
            'author_sort' => [self::truncate($b->authorSort, 255), $s],
            'publisher' => [self::truncate($b->publisher, 255), $s],
            'series' => [self::truncate($b->series, 255), $s],
            'series_index' => [$b->seriesIndex, $s],
            'subjects' => [self::truncate(implode(self::SUBJECT_SEPARATOR, $b->subjects), 2000), $s],
            'language' => [self::truncate($b->language, 32), $s],
            'pubdate' => [self::truncate($b->pubdate, 32), $s],
            'pubyear' => [$b->pubyear, $i],
            'description' => [self::truncate($b->description, 20000), $s],
            'isbn' => [self::truncate($b->isbn, 64), $s],
            'has_cover' => [$b->hasCover ? 1 : 0, $i],
            'indexed_at' => [$b->indexedAt, $i],
        ];
    }

    public static function truncate(string $value, int $length): string {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}
