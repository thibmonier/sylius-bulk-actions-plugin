<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Processor;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/** Human label of an item in the bulk action report (order number, product name, e-mail...). */
final class ItemLabeler implements ItemLabelerInterface
{
    public function label(object $item, int|string $id): string
    {
        return match (true) {
            $item instanceof OrderInterface => '#' . $item->getNumber(),
            $item instanceof PaymentInterface => sprintf('#%s (%s)', $item->getOrder()?->getNumber() ?? '?', (string) $item->getMethod()?->getName()),
            $item instanceof ShipmentInterface => sprintf('#%s (%s)', $item->getOrder()?->getNumber() ?? '?', (string) $item->getMethod()?->getName()),
            $item instanceof ProductVariantInterface => sprintf('%s (%s)', (string) ($item->getProduct()?->getName() ?? $item->getCode()), (string) $item->getCode()),
            $item instanceof ProductInterface => sprintf('%s (%s)', (string) $item->getName(), (string) $item->getCode()),
            $item instanceof CustomerInterface => (string) $item->getEmail(),
            $item instanceof \Stringable => (string) $item,
            default => '#' . $id,
        };
    }
}
