<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Registry;

use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;

interface BulkActionRegistryInterface
{
    /** The enabled action of this code, null when it does not exist or is disabled. */
    public function get(string $code): ?BulkActionInterface;

    /** @return list<BulkActionInterface> the enabled actions of a resource (e.g. "sylius.order") */
    public function getForResource(string $resource): array;

    /** @return array<string, BulkActionInterface> every enabled action, by code */
    public function all(): array;
}
