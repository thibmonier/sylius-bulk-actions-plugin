<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Action\RecordingResourceEventDispatcher;
use Tmonier\SyliusBulkActionsPlugin\Action\Customer\RemoveCustomerGroupAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\ToggleProductAction;
use Tmonier\SyliusBulkActionsPlugin\Registry\BulkActionRegistry;
use Tmonier\SyliusBulkActionsPlugin\Twig\BulkActionsExtension;

final class BulkActionRegistryTest extends TestCase
{
    public function testItIndexesTheActionsByCodeAndResource(): void
    {
        $events = new RecordingResourceEventDispatcher();
        $enable = new ToggleProductAction(true, $events);
        $disable = new ToggleProductAction(false, $events);
        $removeGroup = new RemoveCustomerGroupAction($events);
        $registry = new BulkActionRegistry([$enable, $disable, $removeGroup]);

        self::assertSame($disable, $registry->get('product_disable'));
        self::assertNull($registry->get('unknown'));
        self::assertSame([$enable, $disable], $registry->getForResource('sylius.product'));
        self::assertSame([$removeGroup], $registry->getForResource('sylius.customer'));
        self::assertSame(['product_enable', 'product_disable', 'customer_remove_group'], array_keys($registry->all()));
    }

    public function testDisabledActionsAreNotAvailable(): void
    {
        $events = new RecordingResourceEventDispatcher();
        $registry = new BulkActionRegistry([new ToggleProductAction(true, $events), new ToggleProductAction(false, $events)], ['product_disable']);

        self::assertNull($registry->get('product_disable'));
        self::assertCount(1, $registry->getForResource('sylius.product'));
    }

    public function testTwoActionsCannotShareACode(): void
    {
        $events = new RecordingResourceEventDispatcher();
        $registry = new BulkActionRegistry([new ToggleProductAction(true, $events), new ToggleProductAction(true, $events)]);

        $this->expectException(\LogicException::class);
        $registry->all();
    }

    public function testTheTwigFunctionCanRestrictAndOrderTheActions(): void
    {
        $events = new RecordingResourceEventDispatcher();
        $enable = new ToggleProductAction(true, $events);
        $disable = new ToggleProductAction(false, $events);
        $extension = new BulkActionsExtension(new BulkActionRegistry([$enable, $disable]));

        self::assertSame([$enable, $disable], $extension->getActions('sylius.product'));
        self::assertSame([$disable, $enable], $extension->getActions('sylius.product', ['product_disable', 'unknown', 'product_enable']));
        self::assertSame([$disable], $extension->getActions('sylius.product', 'product_disable'));
        self::assertSame([], $extension->getActions('sylius.order'));
        self::assertSame('tmonier_bulk_actions', $extension->getFunctions()[0]->getName());
    }
}
