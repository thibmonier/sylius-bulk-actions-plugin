<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Order;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\StateMachine\TransitionApplierInterface;
use Webmozart\Assert\Assert;

/** Marks the open payment of orders as paid, from the order grid. */
final readonly class CompleteOrderPaymentAction implements BulkActionInterface
{
    private const OPEN_STATES = [
        PaymentInterface::STATE_NEW,
        PaymentInterface::STATE_PROCESSING,
        PaymentInterface::STATE_AUTHORIZED,
    ];

    public function __construct(private TransitionApplierInterface $transitionApplier)
    {
    }

    public function getCode(): string
    {
        return 'order_payment_complete';
    }

    public function getResource(): string
    {
        return 'sylius.order';
    }

    public function getLabel(): string
    {
        return 'tmonier_bulk_actions.action.order_payment_complete';
    }

    public function getIcon(): string
    {
        return 'tabler:credit-card-pay';
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

        $payment = null;
        foreach (self::OPEN_STATES as $state) {
            $payment = $item->getLastPayment($state);
            if ($payment instanceof \Sylius\Component\Core\Model\PaymentInterface) {
                break;
            }
        }
        if (!$payment instanceof \Sylius\Component\Core\Model\PaymentInterface) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.no_open_payment');
        }

        return $this->transitionApplier->apply(
            $payment,
            PaymentTransitions::GRAPH,
            PaymentTransitions::TRANSITION_COMPLETE,
            'sylius.payment',
            'complete',
        );
    }
}
