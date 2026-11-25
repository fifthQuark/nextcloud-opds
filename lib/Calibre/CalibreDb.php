<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

/**
 * Reads and changes an opened metadata.db. Knows nothing about Nextcloud; the
 * files belonging to the library are handled by CalibreLibrary.
 */
class CalibreDb {
    /** Schema versions (PRAGMA user_version) that can be changed: Calibre 4 to 9 */
    private const MIN_VERSION = 23;
    private const MAX_VERSION = 27;

    public const FIELDS = ['title', 'sort', 'authors', 'author_sort', 'series', 'series_index', 'tags', 'publisher', 'pubdate', 'languages', 'comments', 'rating', 'identifiers'];

    /** Part of every revision. Increase it when more is read from a record, so that known books are read again */
    private const READER = 2;

    private Sqlite $db;
    /** @var list<array{id: int, label: string, name: string, type: string, normalized: bool, hierarchy: bool, order: array<string,int>}>|null */
    private ?array $columns = null;
    private ?string $structure = null;

    public function __construct(string $path, private \DateTimeZone $timezone) {
        $this->db = new Sqlite($path);
        try {
            $tables = array_column($this->db->query("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name');
        } catch (\Throwable $e) {
            $this->db->close();
            throw new CalibreException('metadata.db is not a readable Calibre database');
        }
        foreach (['books', 'authors', 'data', 'books_authors_link', 'identifiers', 'languages', 'metadata_dirtied'] as $table) {
            if (!in_array($table, $tables, true)) {
                $this->db->close();
                throw new CalibreException('metadata.db is not a Calibre database of a supported version');
            }
        }
    }

    public static function create(string $path, \DateTimeZone $timezone): self {
        $schema = file_get_contents(__DIR__ . '/../../resources/calibre-metadata.sql');
        if ($schema === false) {
            throw new CalibreException('The schema of the Calibre database is missing from the app');
        }
        $sqlite = new Sqlite($path);
        try {
            $sqlite->exec($schema);
            $sqlite->run('INSERT INTO library_id (uuid) VALUES (?)', [Naming::uuid4()]);
        } catch (\Throwable $e) {
            throw new CalibreException('SQLite on this server cannot create a Calibre database: ' . $e->getMessage());
        } finally {
            $sqlite->close();
        }
        return new self($path, $timezone);
    }

    public function close(): void {
        $this->db->close();
    }

    /** @return string '' when the database can be changed, otherwise the reason why not */
    public function readOnlyReason(): string {
        $version = (int)$this->db->value('PRAGMA user_version');
        if ($version > self::MAX_VERSION) {
            return 'This library was made by a newer version of Calibre than this app knows, so it can only be read';
        }
        if ($version < self::MIN_VERSION) {
            return 'This library was made by an old version of Calibre. Open it once in a current Calibre to make it editable';
        }
        return '';
    }

    /**
     * Runs changes as one transaction and checks that the file is sound afterwards.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        foreach (['books', 'data', 'authors', 'books_authors_link'] as $table) {
            if ($this->db->value('PRAGMA quick_check(' . $table . ')') !== 'ok') {
                throw new CalibreException('The Calibre database is damaged. Nothing was changed; use "Check library" in Calibre');
            }
        }
        // The file is uploaded as a whole, so nothing may be left in a side file
        $this->db->query('PRAGMA journal_mode = DELETE');
        return $result;
    }

    /** A part of a transaction that can fail on its own. */
    public function savepoint(callable $fn): void {
        $this->db->exec('SAVEPOINT part');
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK TO part');
            $this->db->exec('RELEASE part');
            throw $e;
        }
        $this->db->exec('RELEASE part');
    }

    // ---------------------------------------------------------------- reading

    /** @return array<string,true> keys of the paths of all book folders */
    public function bookFolders(): array {
        $map = [];
        foreach ($this->db->query("SELECT path FROM books WHERE path != ''") as $row) {
            $map[self::pathKey((string)$row['path'])] = true;
        }
        return $map;
    }

    /** @return array<string,int> relative path of every book file, lower-cased => book id */
    public function filePaths(): array {
        $map = [];
        foreach ($this->db->query('SELECT b.id, b.path, d.name, d.format FROM data d JOIN books b ON b.id = d.book') as $row) {
            $map[self::pathKey((string)$row['path'] . '/' . $row['name'] . '.' . $row['format'])] = (int)$row['id'];
        }
        return $map;
    }

    public static function pathKey(string $path): string {
        $path = mb_strtolower(trim($path, '/'));
        return class_exists(\Normalizer::class) ? (\Normalizer::normalize($path) ?: $path) : $path;
    }

    /** @return array<int,string> book id => revision that changes with every edit */
    public function revisions(): array {
        $map = [];
        foreach ($this->db->query('SELECT id, last_modified, path FROM books') as $row) {
            $map[(int)$row['id']] = $this->revision($row);
        }
        return $map;
    }

    public function count(): int {
        return (int)$this->db->value('SELECT COUNT(*) FROM books');
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array {
        $row = $this->db->query('SELECT * FROM books WHERE id = ?', [$id])[0] ?? null;
        if ($row === null) {
            return null;
        }
        $column = fn (string $sql): array => array_map('strval', array_column($this->db->query($sql, [$id]), 'v'));
        $identifiers = [];
        foreach ($this->db->query('SELECT type, val FROM identifiers WHERE book = ? ORDER BY type', [$id]) as $r) {
            $identifiers[(string)$r['type']] = (string)$r['val'];
        }
        $authorSorts = [];
        foreach ($this->db->query('SELECT a.name, a.sort FROM books_authors_link l JOIN authors a ON a.id = l.author WHERE l.book = ?', [$id]) as $r) {
            if (trim((string)$r['sort']) !== '') {
                $authorSorts[str_replace('|', ',', (string)$r['name'])] = str_replace('|', ',', (string)$r['sort']);
            }
        }
        $formats = [];
        foreach ($this->db->query('SELECT format, name FROM data WHERE book = ? ORDER BY format', [$id]) as $r) {
            $formats[strtoupper((string)$r['format'])] = (string)$r['name'];
        }
        $series = $column('SELECT s.name AS v FROM books_series_link l JOIN series s ON s.id = l.series WHERE l.book = ?')[0] ?? '';
        return [
            'id' => $id,
            'uuid' => (string)$row['uuid'],
            'path' => (string)$row['path'],
            'has_cover' => (bool)$row['has_cover'],
            'timestamp' => (string)$row['timestamp'],
            'rev' => $this->revision($row),
            'title' => (string)$row['title'],
            'sort' => (string)$row['sort'],
            // Calibre stores a comma inside a name as |
            'authors' => array_map(static fn (string $a): string => str_replace('|', ',', $a),
                $column('SELECT a.name AS v FROM books_authors_link l JOIN authors a ON a.id = l.author WHERE l.book = ? ORDER BY l.id')),
            'author_sort' => (string)$row['author_sort'],
            'author_sorts' => $authorSorts,
            'series' => $series,
            'series_index' => $series !== '' ? (float)$row['series_index'] : 0.0,
            'tags' => $column('SELECT t.name AS v FROM books_tags_link l JOIN tags t ON t.id = l.tag WHERE l.book = ? ORDER BY t.name'),
            'publisher' => $column('SELECT p.name AS v FROM books_publishers_link l JOIN publishers p ON p.id = l.publisher WHERE l.book = ?')[0] ?? '',
            'pubdate' => $this->dateFromDb((string)$row['pubdate']),
            'pubdate_utc' => (string)$row['pubdate'],
            'languages' => $column('SELECT g.lang_code AS v FROM books_languages_link l JOIN languages g ON g.id = l.lang_code WHERE l.book = ? ORDER BY l.item_order'),
            'comments' => $column('SELECT text AS v FROM comments WHERE book = ?')[0] ?? '',
            'rating' => (int)($column('SELECT r.rating AS v FROM books_ratings_link l JOIN ratings r ON r.id = l.rating WHERE l.book = ?')[0] ?? 0),
            'identifiers' => $identifiers,
            'formats' => $formats,
            'custom' => $this->custom($id),
            'tags_hierarchy' => $this->tagsHierarchy(),
        ];
    }

    /**
     * Custom columns. Columns built from a template ("composite") have no stored values.
     *
     * @return list<array{id: int, label: string, name: string, type: string, normalized: bool, hierarchy: bool, order: array<string,int>}>
     */
    public function columns(): array {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $this->columns = [];
        $nested = $this->hierarchies();
        foreach ($this->optional("SELECT id, label, name, datatype, normalized, display FROM custom_columns WHERE mark_for_delete = 0 AND datatype <> 'composite' ORDER BY id") as $row) {
            $display = json_decode((string)$row['display'], true);
            $values = is_array($display) && is_array($display['enum_values'] ?? null) ? array_values(array_filter($display['enum_values'], 'is_string')) : [];
            $this->columns[] = [
                'id' => (int)$row['id'],
                'label' => (string)$row['label'],
                'name' => (string)$row['name'],
                'type' => (string)$row['datatype'],
                'normalized' => (bool)$row['normalized'],
                'hierarchy' => $row['datatype'] === 'text' && in_array('#' . $row['label'], $nested, true),
                'order' => array_flip($values),
            ];
        }
        return $this->columns;
    }

    public function tagsHierarchy(): bool {
        return in_array('tags', $this->hierarchies(), true);
    }

    /** @return list<string> categories in which "A.B" means B below A: "tags", "#label" */
    private function hierarchies(): array {
        $json = $this->optional("SELECT val FROM preferences WHERE key = 'categories_using_hierarchy'")[0]['val'] ?? '';
        $list = json_decode((string)$json, true);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** @return list<array{label: string, name: string, type: string, hierarchy: bool, order: array<string,int>, values: list<string>, text: string}> columns in which the book has a value */
    private function custom(int $id): array {
        $out = [];
        foreach ($this->columns() as $column) {
            $table = 'custom_column_' . $column['id'];
            $extra = $column['type'] === 'series' ? ', l.extra AS x' : '';
            $rows = $this->optional($column['normalized']
                ? "SELECT c.value AS v$extra FROM books_{$table}_link l JOIN $table c ON c.id = l.value WHERE l.book = ? ORDER BY l.id"
                : "SELECT value AS v FROM $table WHERE book = ?", [$id]);
            $values = [];
            $texts = [];
            foreach ($rows as $row) {
                $value = trim((string)match ($column['type']) {
                    'bool' => $row['v'] === null ? '' : ((int)$row['v'] === 1 ? 'Yes' : 'No'),
                    'datetime' => $this->dateFromDb((string)$row['v']),
                    'rating' => (int)$row['v'] > 0 ? (int)$row['v'] : '',
                    'float' => is_numeric($row['v']) ? self::number((float)$row['v']) : '',
                    default => $row['v'],
                });
                if ($value !== '') {
                    $values[] = $value;
                    $texts[] = isset($row['x']) ? $value . ' [' . self::number((float)$row['x']) . ']' : $value;
                }
            }
            if ($values !== []) {
                $out[] = ['label' => $column['label'], 'name' => $column['name'], 'type' => $column['type'], 'hierarchy' => $column['hierarchy'],
                    'order' => $column['order'], 'values' => $values, 'text' => implode(', ', $texts)];
            }
        }
        return $out;
    }

    private static function number(float $n): string {
        return floor($n) === $n && abs($n) < 1e15 ? sprintf('%.0F', $n) : rtrim(rtrim(sprintf('%.4F', $n), '0'), '.');
    }

    /**
     * For tables that are not needed to show a book.
     *
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function optional(string $sql, array $params = []): array {
        try {
            return $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Id of a book with the same title and first author, 0 if there is none. */
    public function findSame(string $title, string $author): int {
        foreach ($this->db->query('SELECT id FROM books WHERE title = ? COLLATE NOCASE', [$title]) as $row) {
            $names = $this->get((int)$row['id'])['authors'] ?? [];
            if (Naming::sameName($names[0] ?? 'Unknown', $author)) {
                return (int)$row['id'];
            }
        }
        return 0;
    }

    /** Whether another book uses this folder. */
    public function pathInUse(string $path, int $exceptId): bool {
        return (int)$this->db->value('SELECT COUNT(*) FROM books WHERE path = ? COLLATE NOCASE AND id <> ?', [$path, $exceptId]) > 0;
    }

    /** @return list<string> existing names for suggestions while editing */
    public function names(string $kind): array {
        $table = match ($kind) {
            'authors', 'series', 'tags', 'publishers' => $kind,
            default => throw new \InvalidArgumentException('Unknown kind ' . $kind),
        };
        $names = array_map('strval', array_column($this->db->query("SELECT name FROM $table ORDER BY name LIMIT 5000"), 'name'));
        return $kind === 'authors' ? array_map(static fn (string $n): string => str_replace('|', ',', $n), $names) : $names;
    }

    // ---------------------------------------------------------------- writing

    /**
     * Changes the given fields of a book and leaves all others alone.
     *
     * @param array<string,mixed> $c any of FIELDS
     */
    public function update(int $id, array $c): void {
        $old = $this->get($id);
        if ($old === null) {
            throw new CalibreException('This book is no longer in the Calibre database');
        }
        $lang = (array_key_exists('languages', $c) ? $c['languages'] : $old['languages'])[0] ?? 'eng';
        if (array_key_exists('title', $c) && $c['title'] !== $old['title']) {
            $this->db->run('UPDATE books SET title = ? WHERE id = ?', [$c['title'], $id]);
            $c['sort'] ??= '';
        }
        if (array_key_exists('sort', $c)) {
            $sort = $c['sort'] !== '' ? $c['sort'] : Naming::titleSort((string)($c['title'] ?? $old['title']), $lang);
            $this->db->run('UPDATE books SET sort = ? WHERE id = ?', [$sort, $id]);
        }
        if (array_key_exists('authors', $c) && $c['authors'] !== $old['authors']) {
            $this->setAuthors($id, $c['authors']);
            $c['author_sort'] ??= '';
        }
        if (array_key_exists('author_sort', $c)) {
            $this->db->run('UPDATE books SET author_sort = ? WHERE id = ?', [$c['author_sort'] !== '' ? $c['author_sort'] : $this->authorSortOf($id), $id]);
        }
        if (array_key_exists('series', $c)) {
            $this->setLinks($id, 'series', 'series', $c['series'] !== '' ? [$c['series']] : []);
            if ($c['series'] === '') {
                $c['series_index'] = 1.0;
            }
        }
        if (array_key_exists('series_index', $c)) {
            $this->db->run('UPDATE books SET series_index = ? WHERE id = ?', [(float)$c['series_index'], $id]);
        }
        if (array_key_exists('tags', $c)) {
            $this->setLinks($id, 'tags', 'tag', $c['tags']);
        }
        if (array_key_exists('publisher', $c)) {
            $this->setLinks($id, 'publishers', 'publisher', $c['publisher'] !== '' ? [$c['publisher']] : []);
        }
        if (array_key_exists('languages', $c)) {
            $this->setLanguages($id, $c['languages']);
        }
        if (array_key_exists('pubdate', $c)) {
            $this->db->run('UPDATE books SET pubdate = ? WHERE id = ?', [$this->dateToDb((string)$c['pubdate']), $id]);
        }
        if (array_key_exists('comments', $c)) {
            $this->db->run('DELETE FROM comments WHERE book = ?', [$id]);
            if ($c['comments'] !== '') {
                $this->db->run('INSERT INTO comments (book, text) VALUES (?, ?)', [$id, $c['comments']]);
            }
        }
        if (array_key_exists('rating', $c)) {
            $this->setRating($id, (int)$c['rating']);
        }
        if (array_key_exists('identifiers', $c)) {
            $this->db->run('DELETE FROM identifiers WHERE book = ?', [$id]);
            foreach ($c['identifiers'] as $type => $value) {
                $this->db->run('INSERT INTO identifiers (book, type, val) VALUES (?, ?, ?)', [$id, (string)$type, (string)$value]);
            }
        }
        $this->touch($id);
    }

    /** @param array<string,mixed> $fields see FIELDS */
    public function add(array $fields, ?int $added = null): int {
        if (($fields['series'] ?? '') === '') {
            unset($fields['series'], $fields['series_index']);
        }
        $this->db->run('INSERT INTO books (title, series_index, author_sort, pubdate, timestamp) VALUES (?, ?, ?, ?, ?)',
            [$fields['title'], (float)($fields['series_index'] ?? 1.0), '', $this->dateToDb(''), self::now($added)]);
        $id = $this->db->lastId();
        $fields['sort'] ??= '';
        $this->update($id, $fields);
        return $id;
    }

    /** @param array<string,string> $formats format => file name without extension */
    public function setFiles(int $id, string $path, array $formats): void {
        $this->db->run('UPDATE books SET path = ? WHERE id = ?', [$path, $id]);
        foreach ($formats as $format => $name) {
            $this->db->run('UPDATE data SET name = ? WHERE book = ? AND format = ?', [$name, $id, $format]);
        }
        $this->touch($id);
    }

    public function addFormat(int $id, string $format, string $name, int $size): void {
        $this->db->run('INSERT INTO data (book, format, uncompressed_size, name) VALUES (?, ?, ?, ?)', [$id, strtoupper($format), $size, $name]);
        $this->touch($id);
    }

    public function setHasCover(int $id, bool $has): void {
        $this->db->run('UPDATE books SET has_cover = ? WHERE id = ?', [$has ? 1 : 0, $id]);
        $this->touch($id);
    }

    // -------------------------------------------------------------- internals

    /** Marks a book as changed, also for Calibre, which then rewrites its metadata.opf. */
    private function touch(int $id): void {
        $this->db->run('UPDATE books SET last_modified = ? WHERE id = ?', [self::now(), $id]);
        $this->db->run('INSERT OR IGNORE INTO metadata_dirtied (book) VALUES (?)', [$id]);
    }

    /** @param list<string> $authors */
    private function setAuthors(int $id, array $authors): void {
        $this->db->run('DELETE FROM books_authors_link WHERE book = ?', [$id]);
        $seen = [];
        foreach ($authors === [] ? ['Unknown'] : $authors as $author) {
            $item = $this->item('authors', str_replace(',', '|', $author), Naming::authorSort($author));
            if (!isset($seen[$item])) {
                $seen[$item] = true;
                $this->db->run('INSERT INTO books_authors_link (book, author) VALUES (?, ?)', [$id, $item]);
            }
        }
        $this->dropUnused('authors', 'author');
    }

    private function authorSortOf(int $id): string {
        $rows = $this->db->query('SELECT a.name, a.sort FROM books_authors_link l JOIN authors a ON a.id = l.author WHERE l.book = ? ORDER BY l.id', [$id]);
        return implode(' & ', array_map(static fn (array $r): string => (string)($r['sort'] !== null && $r['sort'] !== '' ? $r['sort'] : Naming::authorSort(str_replace('|', ',', (string)$r['name']))), $rows));
    }

    /** @param list<string> $values */
    private function setLinks(int $id, string $table, string $column, array $values): void {
        $this->db->run("DELETE FROM books_{$table}_link WHERE book = ?", [$id]);
        $seen = [];
        foreach ($values as $value) {
            $item = $this->item($table, $value);
            if (!isset($seen[$item])) {
                $seen[$item] = true;
                $this->db->run("INSERT INTO books_{$table}_link (book, $column) VALUES (?, ?)", [$id, $item]);
            }
        }
        $this->dropUnused($table, $column);
    }

    /** @param list<string> $codes */
    private function setLanguages(int $id, array $codes): void {
        $this->db->run('DELETE FROM books_languages_link WHERE book = ?', [$id]);
        foreach (array_values(array_unique($codes)) as $order => $code) {
            $item = $this->db->value('SELECT id FROM languages WHERE lang_code = ?', [$code]);
            if ($item === null) {
                $this->db->run('INSERT INTO languages (lang_code) VALUES (?)', [$code]);
                $item = $this->db->lastId();
            }
            $this->db->run('INSERT INTO books_languages_link (book, lang_code, item_order) VALUES (?, ?, ?)', [$id, (int)$item, $order]);
        }
        $this->dropUnused('languages', 'lang_code');
    }

    private function setRating(int $id, int $rating): void {
        $this->db->run('DELETE FROM books_ratings_link WHERE book = ?', [$id]);
        $rating = max(0, min(10, $rating));
        if ($rating > 0) {
            $item = $this->db->value('SELECT id FROM ratings WHERE rating = ?', [$rating]);
            if ($item === null) {
                $this->db->run('INSERT INTO ratings (rating) VALUES (?)', [$rating]);
                $item = $this->db->lastId();
            }
            $this->db->run('INSERT INTO books_ratings_link (book, rating) VALUES (?, ?)', [$id, (int)$item]);
        }
        $this->dropUnused('ratings', 'rating');
    }

    /** Id of the author, series, tag or publisher with this name, created if needed. */
    private function item(string $table, string $name, ?string $sort = null): int {
        $row = $this->db->query("SELECT id, name FROM $table WHERE name = ?", [$name])[0] ?? null;
        if ($row === null) {
            if ($sort !== null) {
                $this->db->run("INSERT INTO $table (name, sort) VALUES (?, ?)", [$name, $sort]);
            } else {
                $this->db->run("INSERT INTO $table (name) VALUES (?)", [$name]);
            }
            return $this->db->lastId();
        }
        if ($row['name'] !== $name) {
            // Same name in other capitals: only corrected while no other book uses it
            $column = match ($table) {
                'authors' => 'author', 'tags' => 'tag', 'publishers' => 'publisher', default => $table,
            };
            if ((int)$this->db->value("SELECT COUNT(*) FROM books_{$table}_link WHERE $column = ?", [(int)$row['id']]) === 0) {
                $this->db->run("UPDATE $table SET name = ? WHERE id = ?", [$name, (int)$row['id']]);
                if ($sort !== null) {
                    $this->db->run("UPDATE $table SET sort = ? WHERE id = ?", [$sort, (int)$row['id']]);
                }
            }
        }
        return (int)$row['id'];
    }

    private function dropUnused(string $table, string $column): void {
        $this->db->run("DELETE FROM $table WHERE id NOT IN (SELECT $column FROM books_{$table}_link)");
    }

    /** @param array<string,mixed> $row */
    private function revision(array $row): string {
        // Removing a column or changing how one is shown does not touch the books themselves
        $this->structure ??= sha1(json_encode([self::READER, $this->columns(), $this->hierarchies()], JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
        return 'c' . substr(sha1($row['id'] . '|' . $row['last_modified'] . '|' . $row['path'] . '|' . $this->structure), 0, 24);
    }

    private static function now(?int $time = null): string {
        $utc = new \DateTimeZone('UTC');
        $date = $time !== null && $time > 0 ? (new \DateTimeImmutable('@' . $time))->setTimezone($utc) : new \DateTimeImmutable('now', $utc);
        return $date->format('Y-m-d H:i:s.uP');
    }

    /** Day in the user's time zone; Calibre marks "no date" with the year 101. */
    private function dateFromDb(string $value): string {
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return '';
        }
        return (int)$date->format('Y') <= 101 ? '' : $date->setTimezone($this->timezone)->format('Y-m-d');
    }

    private function dateToDb(string $value): string {
        if ($value === '') {
            return '0101-01-01 00:00:00+00:00';
        }
        $date = new \DateTimeImmutable($value . ' 00:00:00', $this->timezone);
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }
}
