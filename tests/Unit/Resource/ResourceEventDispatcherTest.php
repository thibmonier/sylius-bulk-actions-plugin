<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Resource;

use PHPUnit\Framework\TestCase;
use Sylius\Resource\Symfony\EventDispatcher\GenericEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcher;

final class ResourceEventDispatcherTest extends TestCase
{
    public function testItDispatchesThePreAndPostEventsOfTheResourceController(): void
    {
        $subject = new \stdClass();
        $dispatcher = new EventDispatcher();
        $received = [];
        foreach (['sylius.shipment.pre_ship', 'sylius.shipment.post_ship'] as $name) {
            $dispatcher->addListener($name, static function (GenericEvent $event) use (&$received, $name, $subject): void {
                self::assertSame($subject, $event->getSubject());
                $received[] = $name;
            });
        }
        $resourceEventDispatcher = new ResourceEventDispatcher($dispatcher);

        self::assertNull($resourceEventDispatcher->dispatchPreEvent('sylius.shipment', 'ship', $subject));
        $callback = $resourceEventDispatcher->postEventCallback('sylius.shipment', 'ship', $subject);
        self::assertSame(['sylius.shipment.pre_ship'], $received);
        $callback();
        self::assertSame(['sylius.shipment.pre_ship', 'sylius.shipment.post_ship'], $received);
    }

    public function testAStoppedPreEventGivesASkippedResultWithItsMessage(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('sylius.payment.pre_complete', static function (GenericEvent $event): void {
            $event->stop('sylius.resource.payment.cannot_be_completed', GenericEvent::TYPE_ERROR, ['%reason%' => 'stock', '%object%' => new \stdClass()]);
        });

        $result = (new ResourceEventDispatcher($dispatcher))->dispatchPreEvent('sylius.payment', 'complete', new \stdClass());

        self::assertNotNull($result);
        self::assertTrue($result->isSkipped());
        self::assertSame('sylius.resource.payment.cannot_be_completed', $result->getReason());
        self::assertSame(['%reason%' => 'stock'], $result->getParameters());
    }

    public function testAStoppedPreEventWithoutMessageHasAGenericReason(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('sylius.product.pre_update', static function (GenericEvent $event): void {
            $event->stopPropagation();
            $event->stop('');
        });

        $result = (new ResourceEventDispatcher($dispatcher))->dispatchPreEvent('sylius.product', 'update', new \stdClass());

        self::assertSame('tmonier_bulk_actions.skip.stopped_by_listener', $result?->getReason());
    }
}
