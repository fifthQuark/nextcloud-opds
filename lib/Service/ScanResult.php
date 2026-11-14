<?php

declare(strict_types=1);

namespace OCA\Opds\Service;

class ScanResult {
    public int $total = 0;
    public int $added = 0;
    public int $updated = 0;
    public int $removed = 0;
    public int $unchanged = 0;
    public int $errors = 0;
    public bool $complete = true;
    public string $error = '';
    public float $seconds = 0.0;
    public int $finishedAt = 0;

    public function summary(): string {
        if ($this->error !== '') {
            return $this->error;
        }
        $parts = sprintf('%d books: %d added, %d updated, %d removed, %d unchanged', $this->total, $this->added, $this->updated, $this->removed, $this->unchanged);
        if ($this->errors > 0) {
            $parts .= sprintf(', %d failed', $this->errors);
        }
        if (!$this->complete) {
            $parts .= ' (time limit reached, the rest is indexed on the next request)';
        }
        return $parts . sprintf(' in %.1fs', $this->seconds);
    }

    public function toJson(): string {
        return json_encode(get_object_vars($this) + ['summary' => $this->summary()], JSON_THROW_ON_ERROR);
    }
}
