<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\StateMachine;

use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;

interface TransitionApplierInterface
{
    /**
     * Applies a state machine transition the way the Sylius admin does for one resource:
     * can() first (skipped when not allowed), then the resource "pre" event (skipped when a
     * listener stops it), the optional $beforeApply callback, apply(), and the "post" event
     * once the change is flushed.
     *
     * @param string $resource Sylius resource alias of the subject, e.g. "sylius.payment"
     * @param string $event resource controller event name of the single admin action, e.g. "complete"
     */
    public function apply(
        object $subject,
        string $graph,
        string $transition,
        string $resource,
        string $event,
        ?\Closure $beforeApply = null,
    ): BulkActionResult;
}
