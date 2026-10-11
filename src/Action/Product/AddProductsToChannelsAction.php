<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Product;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\ProductChannelsType;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;
use Webmozart\Assert\Assert;

/**
 * Makes the products available in channels. A product is skipped when one of its variants has no
 * price in a new channel: Sylius cannot sell a variant without channel pricing, and prices are
 * not guessed (the channels may use different currencies).
 */
final class AddProductsToChannelsAction implements BulkActionInterface
{
    public function __construct(private readonly ResourceEventDispatcherInterface $resourceEventDispatcher)
    {
    }

    public function getCode(): string
    {
        return 'product_add_channels';
    }

    public function getResource(): string
    {
        return 'sylius.product';
    }

    public function getLabel(): string
    {
        return 'tmonier_bulk_actions.action.product_add_channels';
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
            if (!$item->hasChannel($channel)) {
                $channels[] = $channel;
            }
        }
        if ([] === $channels) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.already_in_channels');
        }

        foreach ($channels as $channel) {
            $missingPrice = $this->findVariantWithoutPrice($item, $channel);
            if (null !== $missingPrice) {
                return BulkActionResult::skipped('tmonier_bulk_actions.skip.missing_channel_pricing', [
                    '%variant%' => (string) $missingPrice->getCode(),
                    '%channel%' => (string) $channel->getName(),
                ]);
            }
        }

        $stopped = $this->resourceEventDispatcher->dispatchPreEvent('sylius.product', 'update', $item);
        if (null !== $stopped) {
            return $stopped;
        }

        foreach ($channels as $channel) {
            $item->addChannel($channel);
        }

        return BulkActionResult::applied($this->resourceEventDispatcher->postEventCallback('sylius.product', 'update', $item));
    }

    private function findVariantWithoutPrice(ProductInterface $product, ChannelInterface $channel): ?ProductVariantInterface
    {
        foreach ($product->getVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface && !$variant->hasChannelPricingForChannel($channel)) {
                return $variant;
            }
        }

        return null;
    }
}
