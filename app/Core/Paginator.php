<?php
declare(strict_types=1);

namespace App\Core;

final class Paginator
{
    public int $page;
    public int $pages;
    public int $offset;

    public function __construct(
        public readonly int $total,
        int $page,
        public readonly int $perPage = 10
    ) {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = max(1, min($page, $this->pages));
        $this->offset = ($this->page - 1) * $perPage;
    }

    public static function fromRequest(Request $request, int $total, int $perPage = 10): self
    {
        return new self($total, (int) ($request->query['page'] ?? 1), $perPage);
    }

    public function hasPrev(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages;
    }

    public function prevPage(): int
    {
        return max(1, $this->page - 1);
    }

    public function nextPage(): int
    {
        return min($this->pages, $this->page + 1);
    }
}
