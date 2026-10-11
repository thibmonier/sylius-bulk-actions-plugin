<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Product;

use Sylius\Component\Core\Model\ProductInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;
use Webmozart\Assert\Assert;

/** Enables or disables products ("product_enable" / "product_disable"). */
final class ToggleProductAction implements BulkActionInterface
{
    public function __construct(
        private readonly bool $enable,
        private readonly ResourceEventDispatcherInterface $resourceEventDispatcher,
    ) {
    }

    public function getCode(): string
    {
        return $this->enable ? 'product_enable' : 'product_disable';
    }

    public function getResource(): string
    {
        return 'sylius.product';
    }

    public function getLabel(): string
    {
        return $this->enable ? 'tmonier_bulk_actions.action.product_enable' : 'tmonier_bulk_actions.action.product_disable';
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
        return $item instanceof ProductInterface;
    }

    public function execute(object $item, array $data): BulkActionResult
    {
        Assert::isInstanceOf($item, ProductInterface::class);

        if ($this->enable === $item->isEnabled()) {
            return BulkActionResult::skipped($this->enable ? 'tmonier_bulk_actions.skip.already_enabled' : 'tmonier_bulk_actions.skip.already_disabled');
        }

        $stopped = $this->resourceEventDispatcher->dispatchPreEvent('sylius.product', 'update', $item);
        if (null !== $stopped) {
            return $stopped;
        }

        $item->setEnabled($this->enable);

        return BulkActionResult::applied($this->resourceEventDispatcher->postEventCallback('sylius.product', 'update', $item));
    }
}
