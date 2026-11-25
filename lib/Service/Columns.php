<?php

declare(strict_types=1);

namespace OCA\Opds\Service;

use OCA\Opds\Calibre\CalibreReader;
use OCA\Opds\Calibre\Sqlite;
use OCA\Opds\Db\Book;
use OCA\Opds\Db\BookRepository;

/**
 * What the books of a user can be browsed by besides the standard fields: ratings
 * and the custom columns of Calibre libraries.
 */
class Columns {
    public function __construct(
        private Config $config,
        private BookRepository $repository,
    ) {
    }

    /**
     * Remembers the columns of all libraries after a scan.
     *
     * @param list<CalibreReader> $readers
     * @param bool $partial a library could not be read: keep what is known about it
     */
    public function update(string $uid, array $readers, bool $partial): void {
        $old = $this->stored($uid);
        $columns = $partial ? $old['columns'] : [];
        $subjects = $partial && $old['subjects'];
        foreach ($readers as $reader) {
            $subjects = $subjects || $reader->tagsHierarchy();
            foreach ($reader->columns() as $column) {
                $key = Book::columnKey($column['label']);
                $columns[$key] = ['name' => $column['name'], 'type' => $column['type'],
                    'hierarchy' => $column['hierarchy'] || ($columns[$key]['hierarchy'] ?? false)];
            }
        }
        $new = ['columns' => $columns, 'subjects' => $subjects];
        if ($new !== $old) {
            $this->config->setUserValueRaw($uid, 'calibre_columns', json_encode($new, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    /** @return list<array{key: string, name: string, type: string, hierarchy: bool}> custom columns in which at least one book has a value to browse by */
    public function browsable(string $uid): array {
        $columns = $this->stored($uid)['columns'];
        if ($columns === [] || !$this->config->isCalibreEnabled($uid) || !Sqlite::available()) {
            return [];
        }
        $used = array_flip($this->repository->termKinds($uid));
        $out = [];
        foreach ($columns as $key => $column) {
            if (in_array($column['type'], Book::BROWSABLE, true) && isset($used['#' . $key])) {
                $out[] = ['key' => (string)$key] + $column;
            }
        }
        usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** @return array{key: string, name: string, type: string, hierarchy: bool}|null */
    public function find(string $uid, string $key): ?array {
        foreach ($this->browsable($uid) as $column) {
            if ($column['key'] === $key) {
                return $column;
            }
        }
        return null;
    }

    /** Whether "A.B" means B below A in the tags of this user's libraries. */
    public function nestedSubjects(string $uid): bool {
        return $this->stored($uid)['subjects'] && $this->config->isCalibreEnabled($uid);
    }

    /** @return array<string,string> sort keys that make sense for this user */
    public function sorts(string $uid): array {
        $sorts = Config::SORTS;
        if (!$this->repository->hasRatings($uid)) {
            unset($sorts['rating']);
        }
        return $sorts;
    }

    /** How a value is shown: ratings are stored as 0 to 10. */
    public static function label(string $type, string $value): string {
        if ($type !== 'rating') {
            return $value;
        }
        $n = max(0, min(10, (int)$value));
        return str_repeat('★', intdiv($n, 2)) . ($n % 2 === 1 ? '½' : '');
    }

    /**
     * The entries to show at one level of a nested list. "A.B" is below "A" if "A" is in the list.
     *
     * @template T of array{value: string}
     * @param list<T> $rows
     * @return list<T&array{label: string, children: int}>
     */
    public static function level(array $rows, string $parent): array {
        $known = [];
        foreach ($rows as $row) {
            $known[$row['value']] = true;
        }
        $parentOf = static function (string $value) use ($known): string {
            $at = strrpos($value, '.');
            $up = $at !== false ? substr($value, 0, $at) : '';
            return $up !== '' && isset($known[$up]) ? $up : '';
        };
        $children = [];
        foreach ($rows as $row) {
            $up = $parentOf($row['value']);
            $children[$up] = ($children[$up] ?? 0) + 1;
        }
        $out = [];
        foreach ($rows as $row) {
            $up = $parentOf($row['value']);
            if ($up === $parent) {
                $out[] = $row + ['label' => $up === '' ? $row['value'] : substr($row['value'], strlen($up) + 1), 'children' => $children[$row['value']] ?? 0];
            }
        }
        return $out;
    }

    /** @return array{columns: array<array-key,array{name: string, type: string, hierarchy: bool}>, subjects: bool} */
    private function stored(string $uid): array {
        $data = json_decode($this->config->getUserValueRaw($uid, 'calibre_columns'), true);
        return is_array($data) && is_array($data['columns'] ?? null)
            ? ['columns' => $data['columns'], 'subjects' => (bool)($data['subjects'] ?? false)]
            : ['columns' => [], 'subjects' => false];
    }
}
