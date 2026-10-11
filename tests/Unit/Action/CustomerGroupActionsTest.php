<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Action;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Customer\Model\CustomerGroup;
use Tmonier\SyliusBulkActionsPlugin\Action\Customer\AssignCustomerGroupAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Customer\RemoveCustomerGroupAction;

final class CustomerGroupActionsTest extends TestCase
{
    public function testItAssignsAGroup(): void
    {
        $events = new RecordingResourceEventDispatcher();
        $group = new CustomerGroup();
        $customer = new Customer();
        $customer->setGroup(new CustomerGroup());

        $result = (new AssignCustomerGroupAction($events))->execute($customer, ['group' => $group]);

        self::assertTrue($result->isApplied());
        self::assertSame($group, $customer->getGroup());
        self::assertSame(['sylius.customer.pre_update'], $events->dispatched);
    }

    public function testItSkipsCustomersAlreadyInTheGroup(): void
    {
        $group = new CustomerGroup();
        $customer = new Customer();
        $customer->setGroup($group);

        $result = (new AssignCustomerGroupAction(new RecordingResourceEventDispatcher()))->execute($customer, ['group' => $group]);

        self::assertSame('tmonier_bulk_actions.skip.already_in_group', $result->getReason());
    }

    public function testItRemovesTheGroupAndSkipsCustomersWithoutGroup(): void
    {
        $action = new RemoveCustomerGroupAction(new RecordingResourceEventDispatcher());
        $customer = new Customer();
        $customer->setGroup(new CustomerGroup());

        self::assertTrue($action->execute($customer, [])->isApplied());
        self::assertNull($customer->getGroup());
        self::assertSame('tmonier_bulk_actions.skip.no_group', $action->execute($customer, [])->getReason());
    }
}
