<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Report;

use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;

/** What a bulk action did to every selected item. */
final class BulkActionReport
{
    /** @var list<BulkActionReportItem> */
    private array $items = [];

    public function __construct(private readonly string $actionCode)
    {
    }

    public function getActionCode(): string
    {
        return $this->actionCode;
    }

    public function add(BulkActionReportItem $item): void
    {
        $this->items[] = $item;
    }

    /** @return list<BulkActionReportItem> */
    public function getItems(?string $status = null): array
    {
        if (null === $status) {
            return $this->items;
        }

        return array_values(array_filter($this->items, static fn (BulkActionReportItem $item): bool => $item->getStatus() === $status));
    }

    public function countApplied(): int
    {
        return count($this->getItems(BulkActionResult::APPLIED));
    }

    public function countSkipped(): int
    {
        return count($this->getItems(BulkActionResult::SKIPPED));
    }

    public function countFailed(): int
    {
        return count($this->getItems(BulkActionResult::FAILED));
    }

    public function count(): int
    {
        return count($this->items);
    }
}
