<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Action;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\Shipment;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Action\Order\CancelOrderAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Order\CompleteOrderPaymentAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Payment\CompletePaymentAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Shipment\ShipShipmentAction;
use Tmonier\SyliusBulkActionsPlugin\StateMachine\TransitionApplierInterface;

final class TransitionActionsTest extends TestCase
{
    public function testItCancelsAnOrderThroughTheOrderWorkflow(): void
    {
        $order = new Order();
        $applier = $this->applier($order, 'sylius_order', 'cancel', 'sylius.order', 'update');

        self::assertTrue((new CancelOrderAction($applier))->execute($order, [])->isApplied());
    }

    public function testItCompletesAPayment(): void
    {
        $payment = new Payment();
        $applier = $this->applier($payment, 'sylius_payment', 'complete', 'sylius.payment', 'complete');

        self::assertTrue((new CompletePaymentAction($applier))->execute($payment, [])->isApplied());
    }

    public function testItCompletesTheOpenPaymentOfAnOrder(): void
    {
        $order = new Order();
        $failed = new Payment();
        $failed->setState(PaymentInterface::STATE_FAILED);
        $open = new Payment();
        $open->setState(PaymentInterface::STATE_NEW);
        $order->addPayment($failed);
        $order->addPayment($open);
        $applier = $this->applier($open, 'sylius_payment', 'complete', 'sylius.payment', 'complete');

        self::assertTrue((new CompleteOrderPaymentAction($applier))->execute($order, [])->isApplied());
    }

    public function testItSkipsAnOrderWithoutOpenPayment(): void
    {
        $order = new Order();
        $paid = new Payment();
        $paid->setState(PaymentInterface::STATE_COMPLETED);
        $order->addPayment($paid);
        $applier = $this->createMock(TransitionApplierInterface::class);
        $applier->expects(self::never())->method('apply');

        $result = (new CompleteOrderPaymentAction($applier))->execute($order, []);

        self::assertSame('tmonier_bulk_actions.skip.no_open_payment', $result->getReason());
    }

    public function testItShipsWithoutTrackingCodeFromTheGrid(): void
    {
        $shipment = new Shipment();
        $applier = $this->createMock(TransitionApplierInterface::class);
        $applier->expects(self::once())
            ->method('apply')
            ->with($shipment, 'sylius_shipment', 'ship', 'sylius.shipment', 'ship', null)
            ->willReturn(BulkActionResult::applied())
        ;

        self::assertTrue((new ShipShipmentAction($applier))->execute($shipment, [])->isApplied());
    }

    public function testItSetsTheTrackingCodeOfTheShipmentBeforeShipping(): void
    {
        $shipment = new Shipment();
        $reflection = new \ReflectionProperty(Shipment::class, 'id');
        $reflection->setValue($shipment, 7);
        $applier = $this->createMock(TransitionApplierInterface::class);
        $applier->expects(self::once())
            ->method('apply')
            ->willReturnCallback(static function (object $subject, string $graph, string $transition, string $resource, string $event, ?\Closure $beforeApply): BulkActionResult {
                self::assertNotNull($beforeApply);
                $beforeApply($subject);

                return BulkActionResult::applied();
            })
        ;

        (new ShipShipmentAction($applier))->execute($shipment, [ShipShipmentAction::TRACKING_CODES => [7 => 'TRACK-7', 8 => 'TRACK-8']]);

        self::assertSame('TRACK-7', $shipment->getTracking());
    }

    private function applier(object $subject, string $graph, string $transition, string $resource, string $event): TransitionApplierInterface
    {
        $applier = $this->createMock(TransitionApplierInterface::class);
        $applier->expects(self::once())
            ->method('apply')
            ->with($subject, $graph, $transition, $resource, $event)
            ->willReturn(BulkActionResult::applied())
        ;

        return $applier;
    }
}
