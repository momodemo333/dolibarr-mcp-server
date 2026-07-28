<?php

declare(strict_types=1);

namespace DolibarrMcp\Sql;

/**
 * Outcome of one read-only query, as returned by the host capability.
 *
 * Deliberately dumb: the host decides what truncation means, this only carries
 * the answer back to the tool.
 */
class SqlExecutionResult
{
    /**
     * @param array<int, string>              $columns
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(
        private array $columns,
        private array $rows,
        private int $rowCount,
        private bool $truncated,
        private int $durationMs,
        private int $bytes,
    ) {
    }

    /** @return array<int, string> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'columns' => $this->columns,
            'rows' => $this->rows,
            'row_count' => $this->rowCount,
            'truncated' => $this->truncated,
            'duration_ms' => $this->durationMs,
        ];
    }
}
