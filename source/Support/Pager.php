<?php

namespace Source\Support;

use MovesCode\Pager\Pager as MovesPager;

/**
 * ERP | Class Pager
 *
 * @author Djalma Martins
 * @package Source\Support
 */
class Pager
{
    private MovesPager $pager;

    /**
     * Pager constructor.
     *
     * @param string $link
     * @param null|string $title
     * @param array|null $first
     * @param array|null $last
     */
    public function __construct(string $link, ?string $title = null, ?array $first = null, ?array $last = null)
    {
        $this->pager = new MovesPager($link, $title, $first, $last);
    }

    public function pager(int $total, int $limit = 10, int $page = 1, int $range = 3): self
    {
        $this->pager->pager($total, $limit, $page, $range);
        return $this;
    }

    public function limit(): int
    {
        return $this->pager->limit();
    }

    public function offset(): int
    {
        return $this->pager->offset();
    }

    public function page(): int
    {
        return $this->pager->page();
    }

    public function pages(): int
    {
        return $this->pager->pages();
    }

    public function render(?string $cssClass = null, bool $fixedFirstAndLastPage = true): ?string
    {
        return $this->pager->render($cssClass, $fixedFirstAndLastPage);
    }
}