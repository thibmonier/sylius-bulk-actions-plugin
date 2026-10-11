<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Resource;

use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;

/**
 * Dispatches the "sylius.<resource>.pre_<event>" / "post_<event>" events of the Sylius resource
 * controller, so that the listeners of a single admin action (shipment confirmation e-mail, stock
 * check before completing a payment, catalog promotions refresh...) also run for bulk actions.
 */
interface ResourceEventDispatcherInterface
{
    /**
     * @param string $resource Sylius resource alias, e.g. "sylius.shipment"
     *
     * @return BulkActionResult|null a skipped result when a listener stopped the event
     */
    public function dispatchPreEvent(string $resource, string $event, object $subject): ?BulkActionResult;

    /** A callback dispatching the post event, to run once the change is flushed. */
    public function postEventCallback(string $resource, string $event, object $subject): \Closure;
}
