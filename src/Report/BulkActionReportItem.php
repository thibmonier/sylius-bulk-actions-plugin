<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Report;

use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;

final class BulkActionReportItem
{
    public function __construct(
        private readonly int|string $id,
        private readonly string $label,
        private readonly BulkActionResult $result,
    ) {
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getResult(): BulkActionResult
    {
        return $this->result;
    }

    public function getStatus(): string
    {
        return $this->result->getStatus();
    }
}
