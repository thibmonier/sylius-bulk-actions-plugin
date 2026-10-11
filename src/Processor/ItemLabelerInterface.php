<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Processor;

interface ItemLabelerInterface
{
    public function label(object $item, int|string $id): string;
}
