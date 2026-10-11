<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Customer;

use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;
use Webmozart\Assert\Assert;

/** Removes customers from their group. */
final class RemoveCustomerGroupAction implements BulkActionInterface
{
    public function __construct(private readonly ResourceEventDispatcherInterface $resourceEventDispatcher)
    {
    }

    public function getCode(): string
    {
        return 'customer_remove_group';
    }

    public function getResource(): string
    {
        return 'sylius.customer';
    }

    public function getLabel(): string
    {
        return 'tmonier_bulk_actions.action.customer_remove_group';
    }

    public function getIcon(): string
    {
        return 'tabler:users-minus';
    }

    public function getFormType(): ?string
    {
        return null;
    }

    public function supports(object $item): bool
    {
        return $item instanceof CustomerInterface;
    }

    public function execute(object $item, array $data): BulkActionResult
    {
        Assert::isInstanceOf($item, CustomerInterface::class);

        if (null === $item->getGroup()) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.no_group');
        }

        $stopped = $this->resourceEventDispatcher->dispatchPreEvent('sylius.customer', 'update', $item);
        if (null !== $stopped) {
            return $stopped;
        }

        $item->setGroup(null);

        return BulkActionResult::applied($this->resourceEventDispatcher->postEventCallback('sylius.customer', 'update', $item));
    }
}
