<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Report\BulkActionReport;

/** Dispatched once a bulk action has processed every item ("tmonier_bulk_actions.post_execute"). */
final class BulkActionExecutedEvent extends Event
{
    public const NAME = 'tmonier_bulk_actions.post_execute';

    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly BulkActionInterface $action,
        private readonly BulkActionReport $report,
        private readonly array $data,
    ) {
    }

    public function getAction(): BulkActionInterface
    {
        return $this->action;
    }

    public function getReport(): BulkActionReport
    {
        return $this->report;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }
}
