<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tmonier\SyliusBulkActionsPlugin\DependencyInjection\TmonierSyliusBulkActionsExtension;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->load([]);

        self::assertSame(500, $container->getParameter('tmonier_bulk_actions.max_items'));
        self::assertSame(50, $container->getParameter('tmonier_bulk_actions.batch_size'));
        self::assertSame([], $container->getParameter('tmonier_bulk_actions.disabled_actions'));
        self::assertTrue($container->hasDefinition('tmonier_bulk_actions.action.order_cancel'));
    }

    public function testActionsCanBeDisabledAndTheCapChanged(): void
    {
        $container = $this->load([
            'max_items' => 100,
            'actions' => [
                'order_cancel' => ['enabled' => false],
                'product_enable' => ['enabled' => true],
                'customer_remove_group' => false,
            ],
        ]);

        self::assertSame(100, $container->getParameter('tmonier_bulk_actions.max_items'));
        self::assertSame(['order_cancel', 'customer_remove_group'], $container->getParameter('tmonier_bulk_actions.disabled_actions'));
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new TmonierSyliusBulkActionsExtension())->load([$config], $container);

        return $container;
    }
}
