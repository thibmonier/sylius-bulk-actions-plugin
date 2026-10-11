<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Customer;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\AssignCustomerGroupType;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;
use Webmozart\Assert\Assert;

/** Puts customers in a group (a Sylius customer belongs to one group at most). */
final class AssignCustomerGroupAction implements BulkActionInterface
{
    public function __construct(private readonly ResourceEventDispatcherInterface $resourceEventDispatcher)
    {
    }

    public function getCode(): string
    {
        return 'customer_assign_group';
    }

    public function getResource(): string
    {
        return 'sylius.customer';
    }

    public function getLabel(): string
    {
        return 'tmonier_bulk_actions.action.customer_assign_group';
    }

    public function getIcon(): string
    {
        return 'tabler:users-plus';
    }

    public function getFormType(): ?string
    {
        return AssignCustomerGroupType::class;
    }

    public function supports(object $item): bool
    {
        return $item instanceof CustomerInterface;
    }

    public function execute(object $item, array $data): BulkActionResult
    {
        Assert::isInstanceOf($item, CustomerInterface::class);
        $group = $data['group'] ?? null;
        Assert::isInstanceOf($group, CustomerGroupInterface::class);

        if ($item->getGroup() === $group) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.already_in_group');
        }

        $stopped = $this->resourceEventDispatcher->dispatchPreEvent('sylius.customer', 'update', $item);
        if (null !== $stopped) {
            return $stopped;
        }

        $item->setGroup($group);

        return BulkActionResult::applied($this->resourceEventDispatcher->postEventCallback('sylius.customer', 'update', $item));
    }
}
