<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Shipment;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Action\Shipment\ShipShipmentAction;
use Tmonier\SyliusBulkActionsPlugin\Processor\BulkActionProcessorInterface;
use Tmonier\SyliusBulkActionsPlugin\Report\BulkActionReport;
use Tmonier\SyliusBulkActionsPlugin\Report\BulkActionReportItem;

/**
 * Ships the shipments of pasted "order number;tracking code" lines, each with its own tracking
 * code, through the "shipment_ship" bulk action (same checks, events and e-mails). One line ships
 * one shipment ready to ship of the order; give several lines for an order with several shipments.
 */
final class TrackingCodeShipper
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly BulkActionProcessorInterface $processor,
        private readonly TrackingCodeParser $parser,
    ) {
    }

    /** @return BulkActionReport|null null when no line is valid */
    public function ship(BulkActionInterface $shipAction, string $text): ?BulkActionReport
    {
        $lines = $this->parser->parse($text);
        if ([] === $lines) {
            return null;
        }

        /** @var list<int> $ids */
        $ids = [];
        /** @var array<int, string> $trackingCodes */
        $trackingCodes = [];
        /** @var list<BulkActionReportItem> $unresolved */
        $unresolved = [];
        foreach ($lines as $line) {
            $order = $this->orderRepository->findOneByNumber($line['order_number']);
            if (!$order instanceof OrderInterface) {
                $unresolved[] = new BulkActionReportItem($line['order_number'], '#' . $line['order_number'], BulkActionResult::skipped('tmonier_bulk_actions.skip.unknown_order'));

                continue;
            }

            $shipment = $this->nextReadyShipment($order, $ids);
            $id = $shipment?->getId();
            if (!is_int($id)) {
                $unresolved[] = new BulkActionReportItem($line['order_number'], '#' . $line['order_number'], BulkActionResult::skipped('tmonier_bulk_actions.skip.no_shipment_to_ship'));

                continue;
            }

            $ids[] = $id;
            $trackingCodes[$id] = $line['tracking_code'];
        }

        $report = [] === $ids
            ? new BulkActionReport($shipAction->getCode())
            : $this->processor->process($shipAction, $ids, [ShipShipmentAction::TRACKING_CODES => $trackingCodes]);
        foreach ($unresolved as $item) {
            $report->add($item);
        }

        return $report;
    }

    /** @param list<int> $taken */
    private function nextReadyShipment(OrderInterface $order, array $taken): ?ShipmentInterface
    {
        foreach ($order->getShipments() as $shipment) {
            if (
                ShipmentInterface::STATE_READY === $shipment->getState() &&
                is_int($shipment->getId()) &&
                !in_array($shipment->getId(), $taken, true)
            ) {
                return $shipment;
            }
        }

        return null;
    }
}
