<?php

declare(strict_types=1);

namespace OCA\Opds\Calibre;

/**
 * Minimal SQLite access on top of whichever of PHP's SQLite extensions is
 * installed, with the SQL functions the triggers of a Calibre database call.
 */
class Sqlite {
    private \SQLite3|\PDO $db;

    public static function available(): bool {
        return class_exists(\SQLite3::class) || (class_exists(\PDO::class) && in_array('sqlite', \PDO::getAvailableDrivers(), true));
    }

    public function __construct(string $path) {
        if (class_exists(\SQLite3::class)) {
            $this->db = new \SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $this->db->enableExceptions(true);
            $this->db->busyTimeout(5000);
        } elseif (self::available()) {
            $this->db = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite:' . $path) : new \PDO('sqlite:' . $path);
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } else {
            throw new CalibreException('This server has no SQLite support for PHP (sqlite3 or pdo_sqlite), which is needed for Calibre libraries');
        }
        $this->define('title_sort', static fn ($title): string => Naming::titleSort((string)$title), 1);
        $this->define('uuid4', static fn (): string => Naming::uuid4(), 0);
        // Calibre's triggers call the two functions above
        $this->exec('PRAGMA trusted_schema = ON');
    }

    public function exec(string $sql): void {
        $this->db->exec($sql);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function query(string $sql, array $params = []): array {
        if ($this->db instanceof \SQLite3) {
            $stmt = $this->bind($sql, $params);
            $result = $stmt->execute();
            $rows = [];
            while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                $rows[] = $row;
            }
            $stmt->close();
            return $rows;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @param list<mixed> $params */
    public function run(string $sql, array $params = []): void {
        if ($this->db instanceof \SQLite3) {
            $stmt = $this->bind($sql, $params);
            $stmt->execute();
            $stmt->close();
            return;
        }
        $this->db->prepare($sql)->execute($params);
    }

    /** @param list<mixed> $params */
    public function value(string $sql, array $params = []): mixed {
        $rows = $this->query($sql, $params);
        return $rows === [] ? null : array_values($rows[0])[0];
    }

    public function lastId(): int {
        return $this->db instanceof \SQLite3 ? $this->db->lastInsertRowID() : (int)$this->db->lastInsertId();
    }

    public function close(): void {
        if ($this->db instanceof \SQLite3) {
            $this->db->close();
        } else {
            unset($this->db);
        }
    }

    private function define(string $name, callable $fn, int $args): void {
        if ($this->db instanceof \SQLite3) {
            $this->db->createFunction($name, $fn, $args);
        } elseif (method_exists($this->db, 'createFunction')) {
            $this->db->createFunction($name, $fn, $args);
        } else {
            $this->db->sqliteCreateFunction($name, $fn, $args);
        }
    }

    /** @param list<mixed> $params */
    private function bind(string $sql, array $params): \SQLite3Stmt {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $value) {
            $type = match (true) {
                $value === null => SQLITE3_NULL,
                is_int($value), is_bool($value) => SQLITE3_INTEGER,
                is_float($value) => SQLITE3_FLOAT,
                default => SQLITE3_TEXT,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }
        return $stmt;
    }
}
