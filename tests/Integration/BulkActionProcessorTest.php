<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Resource\Symfony\EventDispatcher\GenericEvent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Action\Shipment\ShipShipmentAction;
use Tmonier\SyliusBulkActionsPlugin\Event\BulkActionExecutedEvent;
use Tmonier\SyliusBulkActionsPlugin\Processor\BulkActionProcessorInterface;
use Tmonier\SyliusBulkActionsPlugin\Processor\TooManyItemsException;
use Tmonier\SyliusBulkActionsPlugin\Registry\BulkActionRegistryInterface;

/**
 * The processor and the core actions against the real state machine, listeners and database
 * (each test in a rolled-back transaction).
 */
final class BulkActionProcessorTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager()->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager()->getConnection()->isTransactionActive()) {
            $this->entityManager()->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testCancellingAnOrderRunsTheOrderWorkflowAndItsCallbacks(): void
    {
        $order = $this->findOne(OrderInterface::class, [
            'state' => OrderInterface::STATE_NEW,
            'shippingState' => OrderShippingStates::STATE_READY,
        ]);
        $newPaymentIds = [];
        foreach ($order->getPayments() as $payment) {
            if (PaymentInterface::STATE_NEW === $payment->getState()) {
                $newPaymentIds[] = $payment->getId();
            }
        }
        $events = $this->listen(['workflow.sylius_order.completed.cancel', 'sylius.order.pre_update', 'sylius.order.post_update', BulkActionExecutedEvent::NAME]);

        $report = $this->processor()->process($this->action('order_cancel'), [$this->idOf($order)]);

        self::assertSame(1, $report->countApplied());
        self::assertSame(['sylius.order.pre_update', 'workflow.sylius_order.completed.cancel', 'sylius.order.post_update', BulkActionExecutedEvent::NAME], $events->getArrayCopy());
        $this->entityManager()->clear();
        $order = $this->reload(OrderInterface::class, $this->idOf($order));
        self::assertSame(OrderInterface::STATE_CANCELLED, $order->getState());
        self::assertSame(OrderShippingStates::STATE_CANCELLED, $order->getShippingState());
        foreach ($order->getPayments() as $payment) {
            if (in_array($payment->getId(), $newPaymentIds, true)) {
                self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
            }
        }
    }

    public function testCompletingAPaymentPaysTheOrderAndRunsTheResourceEvents(): void
    {
        $payment = $this->findOne(PaymentInterface::class, ['state' => PaymentInterface::STATE_NEW]);
        $events = $this->listen(['sylius.payment.pre_complete', 'workflow.sylius_payment.completed.complete', 'sylius.payment.post_complete']);

        $report = $this->processor()->process($this->action('payment_complete'), [$this->idOf($payment)]);

        self::assertSame(1, $report->countApplied());
        self::assertSame(['sylius.payment.pre_complete', 'workflow.sylius_payment.completed.complete', 'sylius.payment.post_complete'], $events->getArrayCopy());
        $this->entityManager()->clear();
        $payment = $this->reload(PaymentInterface::class, $this->idOf($payment));
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    public function testAStoppedPreEventSkipsTheItem(): void
    {
        $payment = $this->findOne(PaymentInterface::class, ['state' => PaymentInterface::STATE_NEW]);
        $this->dispatcher()->addListener('sylius.payment.pre_complete', static function (GenericEvent $event): void {
            $event->stop('sylius.resource.payment.cannot_be_completed');
        });

        $report = $this->processor()->process($this->action('payment_complete'), [$this->idOf($payment)]);

        self::assertSame(1, $report->countSkipped());
        self::assertSame('sylius.resource.payment.cannot_be_completed', $report->getItems()[0]->getResult()->getReason());
        $this->entityManager()->clear();
        self::assertSame(PaymentInterface::STATE_NEW, $this->reload(PaymentInterface::class, $this->idOf($payment))->getState());
    }

    public function testShippingSetsTheTrackingCodeAndSendsTheConfirmationEmailAfterTheFlush(): void
    {
        $shipment = $this->findOne(ShipmentInterface::class, ['state' => ShipmentInterface::STATE_READY]);
        $flushedBeforeEmail = null;
        $this->dispatcher()->addListener('sylius.shipment.post_ship', function () use ($shipment, &$flushedBeforeEmail): void {
            $state = $this->entityManager()->getConnection()->fetchOne('SELECT state FROM sylius_shipment WHERE id = ?', [$shipment->getId()]);
            $flushedBeforeEmail = ShipmentInterface::STATE_SHIPPED === $state;
        });

        $report = $this->processor()->process($this->action(ShipShipmentAction::CODE), [$this->idOf($shipment)], [
            ShipShipmentAction::TRACKING_CODES => [$this->idOf($shipment) => 'TRACK-42'],
        ]);

        self::assertSame(1, $report->countApplied());
        self::assertTrue($flushedBeforeEmail, 'The post_ship event runs once the shipment is flushed.');
        self::assertEmailCount(1);
        $this->entityManager()->clear();
        $shipment = $this->reload(ShipmentInterface::class, $this->idOf($shipment));
        self::assertSame(ShipmentInterface::STATE_SHIPPED, $shipment->getState());
        self::assertSame('TRACK-42', $shipment->getTracking());
    }

    public function testOneFailingItemDoesNotStopTheOthers(): void
    {
        $products = $this->entityManager()->getRepository(ProductInterface::class)->findBy([], ['id' => 'ASC'], 3);
        self::assertCount(3, $products);
        $ids = array_map(fn (ProductInterface $product): int => $this->idOf($product), $products);
        $failingId = $ids[1];
        $action = new class($failingId) implements BulkActionInterface {
            public function __construct(private readonly int $failingId)
            {
            }

            public function getCode(): string
            {
                return 'test_rename';
            }

            public function getResource(): string
            {
                return 'sylius.product';
            }

            public function getLabel(): string
            {
                return 'test';
            }

            public function getIcon(): string
            {
                return 'tabler:test-pipe';
            }

            public function getFormType(): ?string
            {
                return null;
            }

            public function supports(object $item): bool
            {
                return $item instanceof ProductInterface;
            }

            public function execute(object $item, array $data): BulkActionResult
            {
                \assert($item instanceof ProductInterface);
                $item->setEnabled(false);
                if ($item->getId() === $this->failingId) {
                    throw new \RuntimeException('Boom');
                }

                return BulkActionResult::applied();
            }
        };

        $report = $this->processor()->process($action, [...$ids, 999999999]);

        self::assertSame(2, $report->countApplied());
        self::assertSame(1, $report->countFailed());
        self::assertSame(1, $report->countSkipped());
        self::assertSame('tmonier_bulk_actions.fail.unexpected_error', $report->getItems(BulkActionResult::FAILED)[0]->getResult()->getReason());
        self::assertSame('tmonier_bulk_actions.skip.not_found', $report->getItems(BulkActionResult::SKIPPED)[0]->getResult()->getReason());
        $this->entityManager()->clear();
        self::assertFalse($this->reload(ProductInterface::class, $ids[0])->isEnabled());
        self::assertFalse($this->reload(ProductInterface::class, $ids[2])->isEnabled());
        // The half-applied change of the failed item was reverted, not flushed with the batch.
        self::assertTrue($this->reload(ProductInterface::class, $failingId)->isEnabled());
    }

    public function testTheNumberOfItemsIsCapped(): void
    {
        $this->expectException(TooManyItemsException::class);

        $this->processor()->process($this->action('product_disable'), range(1, $this->processor()->getMaxItems() + 1));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param array<string, mixed> $criteria
     *
     * @return T
     */
    private function findOne(string $class, array $criteria): object
    {
        $item = $this->entityManager()->getRepository($class)->findOneBy($criteria, ['id' => 'ASC']);
        if (null === $item) {
            self::markTestSkipped(sprintf('The random fixtures contain no %s matching %s.', $class, json_encode($criteria)));
        }

        return $item;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function reload(string $class, int $id): object
    {
        $item = $this->entityManager()->find($class, $id);
        self::assertNotNull($item);

        return $item;
    }

    /**
     * @param list<string> $eventNames
     *
     * @return \ArrayObject<int, string> the names of the dispatched events, in order
     */
    private function listen(array $eventNames): \ArrayObject
    {
        /** @var \ArrayObject<int, string> $recorder */
        $recorder = new \ArrayObject();
        foreach ($eventNames as $eventName) {
            $this->dispatcher()->addListener($eventName, static function () use ($recorder, $eventName): void {
                $recorder->append($eventName);
            });
        }

        return $recorder;
    }

    private function idOf(object $item): int
    {
        self::assertTrue(method_exists($item, 'getId'));
        $id = $item->getId();
        self::assertIsInt($id);

        return $id;
    }

    private function action(string $code): BulkActionInterface
    {
        /** @var BulkActionRegistryInterface $registry */
        $registry = self::getContainer()->get(BulkActionRegistryInterface::class);
        $action = $registry->get($code);
        self::assertNotNull($action);

        return $action;
    }

    private function processor(): BulkActionProcessorInterface
    {
        /** @var BulkActionProcessorInterface $processor */
        $processor = self::getContainer()->get(BulkActionProcessorInterface::class);

        return $processor;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');

        return $dispatcher;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
