<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Payment;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\StateMachine\TransitionApplierInterface;
use Webmozart\Assert\Assert;

/** Marks payments as paid ("complete" transition, like the "Complete" button of the payment grid). */
final class CompletePaymentAction implements BulkActionInterface
{
    public function __construct(private readonly TransitionApplierInterface $transitionApplier)
    {
    }

    public function getCode(): string
    {
        return 'payment_complete';
    }

    public function getResource(): string
    {
        return 'sylius.payment';
    }

    public function getLabel(): string
    {
        return 'tmonier_bulk_actions.action.payment_complete';
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
        return $item instanceof PaymentInterface;
    }

    public function execute(object $item, array $data): BulkActionResult
    {
        Assert::isInstanceOf($item, PaymentInterface::class);

        return $this->transitionApplier->apply(
            $item,
            PaymentTransitions::GRAPH,
            PaymentTransitions::TRANSITION_COMPLETE,
            'sylius.payment',
            'complete',
        );
    }
}
