<?php

declare(strict_types=1);

namespace OCA\Opds\Db;

use OCA\Opds\Metadata\Metadata;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
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

    /** Formats in the order they are offered when a book exists in several */
    private const FORMAT_ORDER = ['epub', 'kepub', 'azw3', 'azw', 'mobi', 'fb2', 'pdf', 'cbz', 'cbr', 'djvu'];

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

    /** @return array<int,array{etag: string, path: string, rev: string}> keyed by file id */
    public function getStates(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'etag', 'path', 'meta_rev')->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['file_id']] = ['etag' => (string)$row['etag'], 'path' => (string)$row['path'], 'rev' => (string)$row['meta_rev']];
        }
        $result->closeCursor();
        return $map;
    }

    /**
     * One result per book instead of one per file: each entry holds the files
     * (formats) of one book, the preferred format first.
     *
     * @return array{works: list<non-empty-list<Book>>, total: int}
     */
    public function listWorks(string $uid, BookFilter $filter, string $sort, string $order, int $limit, int $offset): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($this->countWorksOf($qb, 'b.work'), 'cnt')->from(self::TABLE, 'b');
        $this->applyFilter($qb, $uid, $filter);
        $result = $qb->executeQuery();
        $total = (int)$result->fetchOne();
        $result->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $qb->select('b.work')->from(self::TABLE, 'b');
        $this->applyFilter($qb, $uid, $filter);
        $i = 0;
        foreach ($this->sortColumns($sort, $order) as $column => $dir) {
            $alias = 'sort' . $i++;
            $qb->selectAlias($dir === 'DESC' ? $qb->func()->max('b.' . $column) : $qb->func()->min('b.' . $column), $alias);
            $qb->addOrderBy($alias, $dir);
        }
        $qb->groupBy('b.work')->addOrderBy('b.work', 'ASC');
        $qb->setMaxResults($limit)->setFirstResult($offset);
        $result = $qb->executeQuery();
        $keys = array_map('strval', array_column($result->fetchAll(), 'work'));
        $result->closeCursor();
        $works = $this->findWorks($uid, $keys);
        return ['works' => array_values(array_filter(array_map(static fn (string $k): array => $works[$k] ?? [], $keys))), 'total' => $total];
    }

    /**
     * @param string[] $keys
     * @return array<string,non-empty-list<Book>> files of each work, the preferred format first
     */
    public function findWorks(string $uid, array $keys): array {
        $works = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')->from(self::TABLE)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->in('work', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $works[(string)$row['work']][] = $this->hydrate($row);
            }
            $result->closeCursor();
        }
        $rank = static function (Book $b): int {
            $at = array_search($b->format, self::FORMAT_ORDER, true);
            return $at === false ? count(self::FORMAT_ORDER) : $at;
        };
        foreach ($works as &$books) {
            usort($books, static fn (Book $a, Book $b): int => [$rank($a), $a->name, $a->id] <=> [$rank($b), $b->name, $b->id]);
        }
        return $works;
    }

    /** @return array<string,int> keys of all works => a number that increases in the order they were first indexed and survives renaming */
    public function listWorkKeys(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('work')->selectAlias($qb->func()->min('id'), 'first_id')->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->groupBy('work')->orderBy('first_id', 'ASC');
        $result = $qb->executeQuery();
        $keys = [];
        foreach ($result->fetchAll() as $row) {
            $keys[(string)$row['work']] = (int)$row['first_id'];
        }
        $result->closeCursor();
        return $keys;
    }

    public function countWorks(string $uid): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($this->countWorksOf($qb, 'work'), 'cnt')->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Values to browse by, with the number of books (not files) and up to three
     * books whose covers can illustrate the entry.
     *
     * @return list<array{value: string, sort: string, count: int, covers: list<Book>}>
     */
    public function browse(string $uid, string $kind, ?BookFilter $filter = null): array {
        $isTerm = in_array($kind, ['author', 'subject'], true) || str_starts_with($kind, '#');
        if (!$isTerm && !in_array($kind, ['publisher', 'series', 'language', 'format', 'pubyear', 'rating'], true)) {
            throw new \InvalidArgumentException('Unsupported kind ' . $kind);
        }
        $groups = $this->browseGroups($uid, $kind, $isTerm, $filter ?? new BookFilter(), []);
        if ($kind === 'language') {
            // Books in several languages
            $groups = $this->browseGroups($uid, $kind, true, $filter ?? new BookFilter(), $groups);
        }
        $out = [];
        foreach ($groups as $g) {
            $out[] = ['value' => $g['value'], 'sort' => $g['sort'], 'count' => count($g['works']), 'covers' => array_values($g['covers'])];
        }
        usort($out, static fn (array $a, array $b): int => $kind === 'pubyear' || $kind === 'rating'
            ? (int)$b['value'] <=> (int)$a['value']
            : [$a['sort'], $a['value']] <=> [$b['sort'], $b['value']]);
        return $out;
    }

    /**
     * @param array<array-key,array{value: string, sort: string, works: array<string,true>, covers: array<string,Book>}> $groups to add to
     * @return array<array-key,array{value: string, sort: string, works: array<string,true>, covers: array<string,Book>}>
     */
    private function browseGroups(string $uid, string $kind, bool $isTerm, BookFilter $filter, array $groups): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('b.work', 'b.file_id', 'b.etag', 'b.meta_rev', 'b.has_cover')->from(self::TABLE, 'b');
        $this->applyFilter($qb, $uid, $filter);
        if ($isTerm) {
            $qb->selectAlias('bt.value', 'val')->selectAlias('bt.value_sort', 'val_sort')
                ->innerJoin('b', self::TERMS, 'bt', $qb->expr()->andX(
                    $qb->expr()->eq('bt.book_id', 'b.id'),
                    $qb->expr()->eq('bt.kind', $qb->createNamedParameter($kind))
                ));
        } else {
            $qb->selectAlias('b.' . $kind, 'val');
        }
        $qb->addOrderBy('b.series', 'ASC')->addOrderBy('b.series_index', 'ASC')->addOrderBy('b.title_sort', 'ASC');

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $value = (string)$row['val'];
            if ($value === '' || (in_array($kind, ['pubyear', 'rating'], true) && (int)$value <= 0)) {
                continue;
            }
            $work = (string)$row['work'];
            $groups[$value] ??= ['value' => $value, 'sort' => $isTerm ? (string)$row['val_sort'] : Sorting::titleSortKey($value), 'works' => [], 'covers' => []];
            $group = &$groups[$value];
            $group['works'][$work] = true;
            if ((int)$row['has_cover'] === 1 && count($group['covers']) < 3 && !isset($group['covers'][$work])) {
                $cover = new Book();
                $cover->fileId = (int)$row['file_id'];
                $cover->etag = (string)$row['etag'];
                $cover->metaRev = (string)$row['meta_rev'];
                $group['covers'][$work] = $cover;
            }
            unset($group);
        }
        $result->closeCursor();
        return $groups;
    }

    /** Fills the "work" column of books indexed before it existed. Returns the number of changed books. */
    public function fillWorks(): int {
        $changed = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'parent', 'name')->from(self::TABLE)
                ->where($qb->expr()->isNull('work'))
                ->setMaxResults(500);
            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();
            foreach ($rows as $row) {
                $book = new Book();
                $book->parent = (string)$row['parent'];
                $book->name = (string)$row['name'];
                $qb = $this->db->getQueryBuilder();
                $qb->update(self::TABLE)
                    ->set('work', $qb->createNamedParameter($book->work()))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
                $changed++;
            }
        } while (count($rows) === 500);
        return $changed;
    }

    /**
     * Distinct values of a multi-valued term (author, subject) with book counts.
     *
     * @return list<array{value: string, sort: string, count: int}>
     */
    public function listTerms(string $uid, string $kind, ?string $letter = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.value', 't.value_sort')
            ->selectAlias($this->countWorksOf($qb, 'b.work'), 'cnt')
            ->from(self::TERMS, 't')
            ->innerJoin('t', self::TABLE, 'b', $qb->expr()->eq('b.id', 't.book_id'))
            ->where($qb->expr()->eq('t.user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('t.kind', $qb->createNamedParameter($kind)))
            ->groupBy('t.value', 't.value_sort');

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            // Libraries may sort the same author differently
            $value = (string)$row['value'];
            $rows[$value] ??= ['value' => $value, 'sort' => (string)$row['value_sort'], 'count' => 0];
            $rows[$value]['count'] += (int)$row['cnt'];
        }
        $result->closeCursor();
        if ($letter !== null && $letter !== '') {
            $letter = Sorting::letterOf($letter);
            $rows = array_filter($rows, static fn (array $r): bool => Sorting::letterOf($r['sort']) === $letter);
        }
        // Databases have different opinions about spaces and punctuation
        usort($rows, static fn (array $a, array $b): int => [$a['sort'], $a['value']] <=> [$b['sort'], $b['value']]);
        return $rows;
    }

    /** @return list<string> kinds of terms the user's books have */
    public function termKinds(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('kind')->from(self::TERMS)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $result = $qb->executeQuery();
        $kinds = array_map('strval', array_column($result->fetchAll(), 'kind'));
        $result->closeCursor();
        return $kinds;
    }

    public function hasRatings(string $uid): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->gt('rating', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $found = $result->fetchOne() !== false;
        $result->closeCursor();
        return $found;
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
            ->selectAlias($this->countWorksOf($qb, 'b.work'), 'cnt')
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
     * Distinct values of a single-valued column (publisher, series, format)
     * with book counts.
     *
     * @return list<array{value: string, sort: string, count: int}>
     */
    public function listColumn(string $uid, string $column, ?string $letter = null, string $direction = 'ASC'): array {
        if (!in_array($column, ['publisher', 'series', 'format', 'pubyear', 'title_letter'], true)) {
            throw new \InvalidArgumentException('Unsupported column ' . $column);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select($column)
            ->selectAlias($this->countWorksOf($qb, 'work'), 'cnt')
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
            ->selectAlias($this->countWorksOf($qb, 'work'), 'cnt')
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
     *
     * Runs in the migration of 1.2.0, before later migrations have added their columns:
     * it must only use columns that exist since 1.0.0, and therefore not save().
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
                $this->saveAuthors($book);
                $changed++;
            }
        } while (count($rows) === 500);
        return $changed;
    }

    /** Stores the authors only, see splitStoredAuthors(). */
    private function saveAuthors(Book $book): void {
        $values = $this->toRow($book);
        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE);
            foreach (['author', 'author_sort'] as $column) {
                [$value, $type] = $values[$column];
                $qb->set($column, $qb->createNamedParameter($value, $type));
            }
            $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($book->id, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            $this->replaceTerms($book);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
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

        $seen = [];
        foreach ($book->terms() as [$kind, $value, $sort]) {
            $value = self::truncate(trim($value), 255);
            $key = $kind . ' ' . mb_strtolower($value);
            if ($value === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
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

    private function countWorksOf(IQueryBuilder $qb, string $column): IQueryFunction {
        return $qb->createFunction('COUNT(DISTINCT ' . $qb->getColumnName($column) . ')');
    }

    private function applyFilter(IQueryBuilder $qb, string $uid, BookFilter $f): void {
        $qb->where($qb->expr()->eq('b.user_id', $qb->createNamedParameter($uid)));

        $termJoins = ['author' => $f->author, 'subject' => $f->subject];
        if ($f->column !== null && $f->column !== '') {
            $termJoins['#' . $f->column] = $f->value;
        }
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

        foreach (['publisher' => $f->publisher, 'series' => $f->series, 'format' => $f->format] as $column => $value) {
            if ($value !== null && $value !== '') {
                $qb->andWhere($qb->expr()->eq('b.' . $column, $qb->createNamedParameter($value)));
            }
        }
        if ($f->language !== null && $f->language !== '') {
            // The first language is a column, others are terms
            $language = $qb->createNamedParameter($f->language);
            $qb->leftJoin('b', self::TERMS, 'lt', $qb->expr()->andX(
                $qb->expr()->eq('lt.book_id', 'b.id'),
                $qb->expr()->eq('lt.kind', $qb->createNamedParameter('language')),
                $qb->expr()->eq('lt.value', $language)
            ));
            $qb->andWhere($qb->expr()->orX($qb->expr()->eq('b.language', $language), $qb->expr()->isNotNull('lt.id')));
        }
        if ($f->rating !== null) {
            $qb->andWhere($qb->expr()->eq('b.rating', $qb->createNamedParameter($f->rating, IQueryBuilder::PARAM_INT)));
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

    /** @return array<string,string> column => direction */
    private function sortColumns(string $sort, string $order): array {
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
            'rating' => ['rating', 'title_sort'],
            default => ['title_sort', 'author_sort'],
        };
        $out = [];
        foreach ($columns as $i => $column) {
            // Secondary keys keep a natural ascending order regardless of direction,
            // except where they belong to the primary key (series index).
            $colDir = ($i === 0 || ($sort === 'series' && $column === 'series_index') || ($sort === 'author' && $column !== 'title_sort')) ? $dir : 'ASC';
            $out[$column] = $colDir;
        }
        return $out;
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
        // Migrations read rows before later ones have added their columns
        $b->metaRev = (string)($row['meta_rev'] ?? '');
        $b->workKey = (string)($row['work'] ?? '');
        $b->rating = (int)($row['rating'] ?? 0);
        $extra = json_decode((string)($row['extra'] ?? ''), true);
        if (is_array($extra)) {
            $b->languages = $extra['languages'] ?? [];
            $b->identifiers = $extra['identifiers'] ?? [];
            $b->authorSorts = $extra['authorSorts'] ?? [];
            $b->subjectsNested = (bool)($extra['nested'] ?? false);
            $b->custom = $extra['custom'] ?? [];
        }
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
            'work' => [$b->work(), $s],
            'meta_rev' => [self::truncate($b->metaRev, 64), $s],
            'rating' => [$b->rating, $i],
            'extra' => $this->extra($b),
        ];
    }

    /** @return array{0: ?string, 1: int} */
    private function extra(Book $b): array {
        $data = array_filter(['languages' => $b->languages, 'identifiers' => $b->identifiers, 'authorSorts' => $b->authorSorts,
            'nested' => $b->subjectsNested, 'custom' => $b->custom]);
        return $data === [] ? [null, IQueryBuilder::PARAM_NULL]
            : [json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), IQueryBuilder::PARAM_STR];
    }

    public static function truncate(string $value, int $length): string {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}
