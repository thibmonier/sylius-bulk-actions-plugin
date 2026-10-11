<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Functional;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Bulk actions applying state machine transitions: orders, payments, shipments. */
final class AdminOrderBulkActionsTest extends WebTestCase
{
    use BulkActionsTestTrait;

    protected function setUp(): void
    {
        $this->setUpClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownClient();
        parent::tearDown();
    }

    public function testTheOrderPaymentAndShipmentGridsOfferTheBulkActions(): void
    {
        $this->loginAdmin();

        foreach (['/admin/orders/' => 'order_cancel', '/admin/payments/' => 'payment_complete', '/admin/shipments/' => 'shipment_ship'] as $url => $code) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-bulk-action="%s"]', $code)), $url);
            self::assertGreaterThan(0, $crawler->filter('input[data-check-all-group="index"]')->count(), $url);
        }
    }

    public function testItCancelsOrdersAndSkipsTheOnesThatCannotBeCancelled(): void
    {
        $this->loginAdmin();
        [$first, $second] = $this->newOrders(2);
        $this->stateMachine()->apply($second, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        $crawler = $this->runBulkAction('order_cancel', [$this->idOf($first), $this->idOf($second)], [], '/admin/orders/');

        self::assertSame(['Cancel: 1 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        self::assertSame([sprintf('#%s: Not possible in the current state.', $second->getNumber())], $this->reportRows($crawler, 'skipped'));
        $this->entityManager()->clear();
        $first = $this->reloadOrder($first);
        self::assertSame(OrderInterface::STATE_CANCELLED, $first->getState());
        // The state machine callbacks ran: payments and shipments are cancelled too.
        foreach ($first->getPayments() as $payment) {
            self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        }
        foreach ($first->getShipments() as $shipment) {
            self::assertSame(ShipmentInterface::STATE_CANCELLED, $shipment->getState());
        }
    }

    public function testItMarksPaymentsAsPaid(): void
    {
        $this->loginAdmin();
        $payment = $this->newPayment();
        $order = $payment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);

        $crawler = $this->runBulkAction('payment_complete', [$this->idOf($payment)], [], '/admin/payments/');

        self::assertSame(['Mark as paid: 1 updated, 0 skipped, 0 failed.'], $this->flashes($crawler));
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(PaymentInterface::class, $this->idOf($payment));
        self::assertInstanceOf(PaymentInterface::class, $reloaded);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $reloaded->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $this->reloadOrder($order)->getPaymentState());
    }

    public function testItMarksTheOpenPaymentOfOrdersAsPaidFromTheOrderGrid(): void
    {
        $this->loginAdmin();
        $payment = $this->newPayment();
        $order = $payment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        [$paid] = $this->newOrders(1, OrderPaymentStates::STATE_PAID, [$this->idOf($order)]);

        $crawler = $this->runBulkAction('order_payment_complete', [$this->idOf($order), $this->idOf($paid)], [], '/admin/orders/');

        self::assertSame(['Mark as paid: 1 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        self::assertSame([sprintf('#%s: No payment to complete.', $paid->getNumber())], $this->reportRows($crawler, 'skipped'));
        $this->entityManager()->clear();
        self::assertSame(OrderPaymentStates::STATE_PAID, $this->reloadOrder($order)->getPaymentState());
    }

    public function testItShipsShipmentsAndSendsTheShipmentConfirmationEmail(): void
    {
        $this->loginAdmin();
        [$ready, $shipped] = $this->readyShipments(2);
        $this->stateMachine()->apply($shipped, 'sylius_shipment', 'ship');
        $this->entityManager()->flush();
        $customerEmail = (string) $ready->getOrder()?->getCustomer()?->getEmail();

        $this->submitBulkAction('shipment_ship', [$this->idOf($ready), $this->idOf($shipped)], [], '/admin/shipments/');

        // Sent by the "sylius.shipment.post_ship" listener, exactly like the single "Ship" action.
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', $customerEmail);

        $crawler = $this->client->followRedirect();
        self::assertSame(['Ship (without tracking code): 1 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(ShipmentInterface::class, $this->idOf($ready));
        self::assertInstanceOf(ShipmentInterface::class, $reloaded);
        self::assertSame(ShipmentInterface::STATE_SHIPPED, $reloaded->getState());
        self::assertNotNull($reloaded->getShippedAt());
    }

    /**
     * Placed orders (state "new") in a payment state; the random fixtures may not have enough,
     * the test is then skipped.
     *
     * @param list<int> $excludedIds
     *
     * @return list<OrderInterface>
     */
    private function newOrders(int $count, ?string $paymentState = null, array $excludedIds = []): array
    {
        $queryBuilder = $this->entityManager()->createQueryBuilder()
            ->select('o')
            ->from(OrderInterface::class, 'o')
            ->andWhere('o.state = :state')
            ->setParameter('state', OrderInterface::STATE_NEW)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($count)
        ;
        if (null !== $paymentState) {
            $queryBuilder->andWhere('o.paymentState = :paymentState')->setParameter('paymentState', $paymentState);
        }
        if ([] !== $excludedIds) {
            $queryBuilder->andWhere('o.id NOT IN (:excluded)')->setParameter('excluded', $excludedIds);
        }

        /** @var list<OrderInterface> $orders */
        $orders = $queryBuilder->getQuery()->getResult();
        if (count($orders) < $count) {
            self::markTestSkipped('The random fixtures do not contain enough placed orders.');
        }

        return $orders;
    }

    private function newPayment(): PaymentInterface
    {
        /** @var PaymentInterface|null $payment */
        $payment = $this->entityManager()->createQueryBuilder()
            ->select('p')
            ->from(PaymentInterface::class, 'p')
            ->innerJoin('p.order', 'o')
            ->andWhere('p.state = :state')
            ->andWhere('o.state = :orderState')
            ->setParameter('state', PaymentInterface::STATE_NEW)
            ->setParameter('orderState', OrderInterface::STATE_NEW)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
        if (null === $payment) {
            self::markTestSkipped('The random fixtures contain no payment awaiting completion.');
        }

        return $payment;
    }

    /** @return list<ShipmentInterface> */
    private function readyShipments(int $count): array
    {
        /** @var list<ShipmentInterface> $shipments */
        $shipments = $this->entityManager()->createQueryBuilder()
            ->select('s')
            ->from(ShipmentInterface::class, 's')
            ->innerJoin('s.order', 'o')
            ->andWhere('s.state = :state')
            ->andWhere('o.state = :orderState')
            ->setParameter('state', ShipmentInterface::STATE_READY)
            ->setParameter('orderState', OrderInterface::STATE_NEW)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults($count)
            ->getQuery()
            ->getResult()
        ;
        if (count($shipments) < $count) {
            self::markTestSkipped('The random fixtures do not contain enough shipments ready to ship.');
        }

        return $shipments;
    }

    private function reloadOrder(OrderInterface $order): OrderInterface
    {
        $reloaded = $this->entityManager()->find(OrderInterface::class, $this->idOf($order));
        self::assertInstanceOf(OrderInterface::class, $reloaded);

        return $reloaded;
    }

    private function stateMachine(): StateMachineInterface
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');

        return $stateMachine;
    }
}
