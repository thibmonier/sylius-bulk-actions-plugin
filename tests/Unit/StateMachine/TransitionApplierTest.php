<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\Shipment;
use Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Action\RecordingResourceEventDispatcher;
use Tmonier\SyliusBulkActionsPlugin\StateMachine\TransitionApplier;

final class TransitionApplierTest extends TestCase
{
    public function testItSkipsWhenTheTransitionIsNotAllowed(): void
    {
        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->method('can')->willReturn(false);
        $stateMachine->expects(self::never())->method('apply');
        $events = new RecordingResourceEventDispatcher();

        $result = (new TransitionApplier($stateMachine, $events))->apply(new Shipment(), 'sylius_shipment', 'ship', 'sylius.shipment', 'ship');

        self::assertSame('tmonier_bulk_actions.skip.transition_not_allowed', $result->getReason());
        self::assertSame([], $events->dispatched, 'No resource event for a refused item.');
    }

    public function testItSkipsWhenAListenerStopsThePreEvent(): void
    {
        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->method('can')->willReturn(true);
        $stateMachine->expects(self::never())->method('apply');

        $result = (new TransitionApplier($stateMachine, new RecordingResourceEventDispatcher('sylius.resource.payment.cannot_be_completed')))
            ->apply(new Shipment(), 'sylius_shipment', 'ship', 'sylius.shipment', 'ship', static fn () => self::fail('Not called'));

        self::assertTrue($result->isSkipped());
        self::assertSame('sylius.resource.payment.cannot_be_completed', $result->getReason());
    }

    public function testItAppliesTheTransitionAndDefersThePostEvent(): void
    {
        $shipment = new Shipment();
        $calls = [];
        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->method('can')->with($shipment, 'sylius_shipment', 'ship')->willReturn(true);
        $stateMachine->expects(self::once())->method('apply')->with($shipment, 'sylius_shipment', 'ship')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'apply';
            })
        ;
        $events = new RecordingResourceEventDispatcher();

        $result = (new TransitionApplier($stateMachine, $events))->apply(
            $shipment,
            'sylius_shipment',
            'ship',
            'sylius.shipment',
            'ship',
            static function () use (&$calls): void {
                $calls[] = 'before';
            },
        );

        self::assertTrue($result->isApplied());
        self::assertSame(['before', 'apply'], $calls);
        self::assertSame(['sylius.shipment.pre_ship'], $events->dispatched);
        $afterFlush = $result->getAfterFlush();
        self::assertNotNull($afterFlush);
        $afterFlush();
        self::assertSame(['sylius.shipment.pre_ship', 'sylius.shipment.post_ship'], $events->dispatched);
    }
}
