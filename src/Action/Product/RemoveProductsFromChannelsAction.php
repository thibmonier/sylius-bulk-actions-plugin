<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Product;

use Sylius\Component\Core\Model\ProductInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\ProductChannelsType;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;
use Webmozart\Assert\Assert;

/** Removes the products from channels (channel pricings are kept, like the product form does). */
final readonly class RemoveProductsFromChannelsAction implements BulkActionInterface
{
    public function __construct(private ResourceEventDispatcherInterface $resourceEventDispatcher)
    {
    }

    public function getCode(): string
    {
        return 'product_remove_channels';
    }

    public function getResource(): string
    {
        return 'sylius.product';
    }

    public function getLabel(): string
    {
        return 'tmonier_bulk_actions.action.product_remove_channels';
    }

    public function getIcon(): string
    {
        return 'tabler:building-store';
    }

    public function getFormType(): string
    {
        return ProductChannelsType::class;
    }

    public function supports(object $item): bool
    {
        return $item instanceof ProductInterface;
    }

    public function execute(object $item, array $data): BulkActionResult
    {
        Assert::isInstanceOf($item, ProductInterface::class);

        $channels = [];
        foreach (ProductChannelsType::channels($data) as $channel) {
            if ($item->hasChannel($channel)) {
                $channels[] = $channel;
            }
        }
        if ([] === $channels) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.not_in_channels');
        }

        $stopped = $this->resourceEventDispatcher->dispatchPreEvent('sylius.product', 'update', $item);
        if ($stopped instanceof \Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult) {
            return $stopped;
        }

        foreach ($channels as $channel) {
            $item->removeChannel($channel);
        }

        return BulkActionResult::applied($this->resourceEventDispatcher->postEventCallback('sylius.product', 'update', $item));
    }
}
