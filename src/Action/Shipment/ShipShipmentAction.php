<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Shipment;

use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\ShipmentTransitions;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\StateMachine\TransitionApplierInterface;
use Webmozart\Assert\Assert;

/**
 * Ships shipments. From the grid there is no tracking code (one code for many parcels makes no
 * sense); the "ship with tracking codes" page gives one code per shipment in
 * $data[self::TRACKING_CODES][shipment id]. The shipment confirmation e-mail is sent by the
 * "sylius.shipment.post_ship" event, like the single "Ship" button.
 */
final class ShipShipmentAction implements BulkActionInterface
{
    public const CODE = 'shipment_ship';

    public const TRACKING_CODES = 'tracking_codes';

    public function __construct(private readonly TransitionApplierInterface $transitionApplier)
    {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getResource(): string
    {
        return 'sylius.shipment';
    }

    public function getLabel(): string
    {
        return 'tmonier_bulk_actions.action.shipment_ship';
    }

    public function getIcon(): string
    {
        return 'tabler:truck-delivery';
    }

    public function getFormType(): ?string
    {
        return null;
    }

    public function supports(object $item): bool
    {
        return $item instanceof ShipmentInterface;
    }

    public function execute(object $item, array $data): BulkActionResult
    {
        Assert::isInstanceOf($item, ShipmentInterface::class);

        $trackingCodes = $data[self::TRACKING_CODES] ?? [];
        $id = $item->getId();
        $trackingCode = is_array($trackingCodes) && (is_int($id) || is_string($id)) ? ($trackingCodes[$id] ?? null) : null;

        return $this->transitionApplier->apply(
            $item,
            ShipmentTransitions::GRAPH,
            ShipmentTransitions::TRANSITION_SHIP,
            'sylius.shipment',
            'ship',
            is_string($trackingCode) && '' !== $trackingCode
                ? static fn (ShipmentInterface $shipment) => $shipment->setTracking($trackingCode)
                : null,
        );
    }
}
