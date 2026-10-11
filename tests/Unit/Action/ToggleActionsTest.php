<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Action;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ShopUser;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Action\Customer\ToggleCustomerAccountAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\ToggleProductAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\ToggleProductVariantAction;

final class ToggleActionsTest extends TestCase
{
    public function testItDisablesAnEnabledProduct(): void
    {
        $events = new RecordingResourceEventDispatcher();
        $action = new ToggleProductAction(false, $events);
        $product = new Product();
        $product->setEnabled(true);

        $result = $action->execute($product, []);

        self::assertTrue($result->isApplied());
        self::assertFalse($product->isEnabled());
        self::assertSame(['sylius.product.pre_update'], $events->dispatched);
        $afterFlush = $result->getAfterFlush();
        self::assertNotNull($afterFlush);
        $afterFlush();
        self::assertSame(['sylius.product.pre_update', 'sylius.product.post_update'], $events->dispatched);
    }

    public function testItSkipsAProductAlreadyInTheWantedState(): void
    {
        $events = new RecordingResourceEventDispatcher();
        $product = new Product();
        $product->setEnabled(true);

        $result = (new ToggleProductAction(true, $events))->execute($product, []);

        self::assertTrue($result->isSkipped());
        self::assertSame('tmonier_bulk_actions.skip.already_enabled', $result->getReason());
        self::assertSame([], $events->dispatched);
    }

    public function testAStoppedPreEventLeavesTheProductUntouched(): void
    {
        $product = new Product();
        $product->setEnabled(true);

        $result = (new ToggleProductAction(false, new RecordingResourceEventDispatcher('sylius.resource.stopped')))->execute($product, []);

        self::assertTrue($result->isSkipped());
        self::assertSame('sylius.resource.stopped', $result->getReason());
        self::assertTrue($product->isEnabled());
    }

    public function testCodesAndSupport(): void
    {
        $events = new RecordingResourceEventDispatcher();

        self::assertSame('product_enable', (new ToggleProductAction(true, $events))->getCode());
        self::assertSame('product_disable', (new ToggleProductAction(false, $events))->getCode());
        self::assertSame('product_variant_enable', (new ToggleProductVariantAction(true, $events))->getCode());
        self::assertSame('customer_disable_account', (new ToggleCustomerAccountAction(false, $events))->getCode());
        self::assertTrue((new ToggleProductAction(true, $events))->supports(new Product()));
        self::assertFalse((new ToggleProductAction(true, $events))->supports(new Order()));
        self::assertNull((new ToggleProductAction(true, $events))->getFormType());
    }

    public function testItEnablesAVariant(): void
    {
        $events = new RecordingResourceEventDispatcher();
        $variant = new ProductVariant();
        $variant->setEnabled(false);

        $result = (new ToggleProductVariantAction(true, $events))->execute($variant, []);

        self::assertTrue($result->isApplied());
        self::assertTrue($variant->isEnabled());
        self::assertSame(['sylius.product_variant.pre_update'], $events->dispatched);
    }

    public function testItDisablesACustomerAccount(): void
    {
        $events = new RecordingResourceEventDispatcher();
        $customer = new Customer();
        $user = new ShopUser();
        $user->setEnabled(true);
        $customer->setUser($user);

        $result = (new ToggleCustomerAccountAction(false, $events))->execute($customer, []);

        self::assertSame(BulkActionResult::APPLIED, $result->getStatus());
        self::assertFalse($user->isEnabled());
        self::assertSame(['sylius.customer.pre_update'], $events->dispatched);
    }

    public function testItSkipsGuestsAndAccountsAlreadyInTheWantedState(): void
    {
        $action = new ToggleCustomerAccountAction(true, new RecordingResourceEventDispatcher());

        self::assertSame('tmonier_bulk_actions.skip.no_account', $action->execute(new Customer(), [])->getReason());

        $customer = new Customer();
        $user = new ShopUser();
        $user->setEnabled(true);
        $customer->setUser($user);
        self::assertSame('tmonier_bulk_actions.skip.already_enabled', $action->execute($customer, [])->getReason());
    }
}
