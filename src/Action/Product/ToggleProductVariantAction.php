<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Product;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;
use Webmozart\Assert\Assert;

/** Enables or disables product variants ("product_variant_enable" / "product_variant_disable"). */
final class ToggleProductVariantAction implements BulkActionInterface
{
    public function __construct(
        private readonly bool $enable,
        private readonly ResourceEventDispatcherInterface $resourceEventDispatcher,
    ) {
    }

    public function getCode(): string
    {
        return $this->enable ? 'product_variant_enable' : 'product_variant_disable';
    }

    public function getResource(): string
    {
        return 'sylius.product_variant';
    }

    public function getLabel(): string
    {
        return $this->enable ? 'tmonier_bulk_actions.action.product_variant_enable' : 'tmonier_bulk_actions.action.product_variant_disable';
    }

    public function getIcon(): string
    {
        return $this->enable ? 'tabler:eye' : 'tabler:eye-off';
    }

    public function getFormType(): ?string
    {
        return null;
    }

    public function supports(object $item): bool
    {
        return $item instanceof ProductVariantInterface;
    }

    public function execute(object $item, array $data): BulkActionResult
    {
        Assert::isInstanceOf($item, ProductVariantInterface::class);

        if ($this->enable === $item->isEnabled()) {
            return BulkActionResult::skipped($this->enable ? 'tmonier_bulk_actions.skip.already_enabled' : 'tmonier_bulk_actions.skip.already_disabled');
        }

        $stopped = $this->resourceEventDispatcher->dispatchPreEvent('sylius.product_variant', 'update', $item);
        if (null !== $stopped) {
            return $stopped;
        }

        $item->setEnabled($this->enable);

        return BulkActionResult::applied($this->resourceEventDispatcher->postEventCallback('sylius.product_variant', 'update', $item));
    }
}
