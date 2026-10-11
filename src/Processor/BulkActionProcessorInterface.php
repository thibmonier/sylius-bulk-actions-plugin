<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Processor;

use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Report\BulkActionReport;

interface BulkActionProcessorInterface
{
    /**
     * @param list<int|string> $ids
     * @param array<string, mixed> $data
     *
     * @throws TooManyItemsException
     */
    public function process(BulkActionInterface $action, array $ids, array $data = []): BulkActionReport;

    /**
     * Labels of the first selected items, for the confirmation page.
     *
     * @param list<int|string> $ids
     *
     * @return list<string>
     */
    public function preview(BulkActionInterface $action, array $ids, int $limit): array;

    public function getMaxItems(): int;
}
