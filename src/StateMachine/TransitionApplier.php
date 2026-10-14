<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\StateMachine;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;

final readonly class TransitionApplier implements TransitionApplierInterface
{
    public function __construct(
        private StateMachineInterface $stateMachine,
        private ResourceEventDispatcherInterface $resourceEventDispatcher,
    ) {
    }

    public function apply(
        object $subject,
        string $graph,
        string $transition,
        string $resource,
        string $event,
        ?\Closure $beforeApply = null,
    ): BulkActionResult {
        if (!$this->stateMachine->can($subject, $graph, $transition)) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.transition_not_allowed');
        }

        $stopped = $this->resourceEventDispatcher->dispatchPreEvent($resource, $event, $subject);
        if ($stopped instanceof \Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult) {
            return $stopped;
        }

        if ($beforeApply instanceof \Closure) {
            $beforeApply($subject);
        }

        $this->stateMachine->apply($subject, $graph, $transition);

        return BulkActionResult::applied($this->resourceEventDispatcher->postEventCallback($resource, $event, $subject));
    }
}
