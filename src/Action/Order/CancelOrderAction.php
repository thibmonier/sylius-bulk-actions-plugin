<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Order;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\OrderTransitions;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\StateMachine\TransitionApplierInterface;
use Webmozart\Assert\Assert;

/** Cancels orders through the "sylius_order" state machine (payments, shipments and stock follow). */
final class CancelOrderAction implements BulkActionInterface
{
    public function __construct(private readonly TransitionApplierInterface $transitionApplier)
    {
    }

    public function getCode(): string
    {
        return 'order_cancel';
    }

    public function getResource(): string
    {
        return 'sylius.order';
    }

    public function getLabel(): string
    {
        return 'tmonier_bulk_actions.action.order_cancel';
    }

    public function getIcon(): string
    {
        return 'tabler:x';
    }

    public function getFormType(): ?string
    {
        return null;
    }

    public function supports(object $item): bool
    {
        return $item instanceof OrderInterface;
    }

    public function execute(object $item, array $data): BulkActionResult
    {
        Assert::isInstanceOf($item, OrderInterface::class);

        return $this->transitionApplier->apply(
            $item,
            OrderTransitions::GRAPH,
            OrderTransitions::TRANSITION_CANCEL,
            'sylius.order',
            'update',
        );
    }
}
