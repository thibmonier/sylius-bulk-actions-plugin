<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Processor;

final class TooManyItemsException extends \RuntimeException
{
    public function __construct(
        public readonly int $count,
        public readonly int $maxItems,
    ) {
        parent::__construct(sprintf('%d items selected, a bulk action processes %d items at most.', $count, $maxItems));
    }
}
