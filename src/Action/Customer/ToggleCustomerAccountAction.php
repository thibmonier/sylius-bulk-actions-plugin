<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Customer;

use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;
use Webmozart\Assert\Assert;

/** Enables or disables the shop account of customers; guests (no account) are skipped. */
final class ToggleCustomerAccountAction implements BulkActionInterface
{
    public function __construct(
        private readonly bool $enable,
        private readonly ResourceEventDispatcherInterface $resourceEventDispatcher,
    ) {
    }

    public function getCode(): string
    {
        return $this->enable ? 'customer_enable_account' : 'customer_disable_account';
    }

    public function getResource(): string
    {
        return 'sylius.customer';
    }

    public function getLabel(): string
    {
        return $this->enable ? 'tmonier_bulk_actions.action.customer_enable_account' : 'tmonier_bulk_actions.action.customer_disable_account';
    }

    public function getIcon(): string
    {
        return $this->enable ? 'tabler:user-check' : 'tabler:user-off';
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

        $user = $item->getUser();
        if (null === $user) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.no_account');
        }
        if ($this->enable === $user->isEnabled()) {
            return BulkActionResult::skipped($this->enable ? 'tmonier_bulk_actions.skip.already_enabled' : 'tmonier_bulk_actions.skip.already_disabled');
        }

        $stopped = $this->resourceEventDispatcher->dispatchPreEvent('sylius.customer', 'update', $item);
        if (null !== $stopped) {
            return $stopped;
        }

        $user->setEnabled($this->enable);

        return BulkActionResult::applied($this->resourceEventDispatcher->postEventCallback('sylius.customer', 'update', $item));
    }
}
