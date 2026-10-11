<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Functional;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminShipWithTrackingCodesTest extends WebTestCase
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

    public function testItShipsEachShipmentWithItsOwnTrackingCode(): void
    {
        $this->loginAdmin();
        $shipments = $this->readyShipmentsOfDistinctOrders(2);
        $numbers = array_map(static fn (ShipmentInterface $shipment): string => (string) $shipment->getOrder()?->getNumber(), $shipments);

        $crawler = $this->client->request('GET', '/admin/shipments/');
        self::assertCount(1, $crawler->filter('[data-test-tmonier-ship-with-tracking-codes]'));

        $crawler = $this->client->request('GET', '/admin/bulk-actions/shipments/ship-with-tracking-codes');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-test-tmonier-ship-with-tracking-codes-submit]')->form([
            'tmonier_bulk_actions_tracking[lines]' => sprintf("%s;TRACK-A\n%s;TRACK-B\nUNKNOWN-1;TRACK-C", $numbers[0], $numbers[1]),
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/shipments/');
        self::assertEmailCount(2);
        $crawler = $this->client->followRedirect();
        self::assertSame(['Ship with tracking codes: 2 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        self::assertSame(['#UNKNOWN-1: No order with this number.'], $this->reportRows($crawler, 'skipped'));
        $this->entityManager()->clear();
        foreach (['TRACK-A', 'TRACK-B'] as $index => $trackingCode) {
            $shipment = $this->entityManager()->find(ShipmentInterface::class, $this->idOf($shipments[$index]));
            self::assertInstanceOf(ShipmentInterface::class, $shipment);
            self::assertSame(ShipmentInterface::STATE_SHIPPED, $shipment->getState());
            self::assertSame($trackingCode, $shipment->getTracking());
        }
    }

    public function testItAsksForAtLeastOneValidLine(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/bulk-actions/shipments/ship-with-tracking-codes');
        $form = $crawler->filter('[data-test-tmonier-ship-with-tracking-codes-submit]')->form([
            'tmonier_bulk_actions_tracking[lines]' => 'nothing useful',
        ]);
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['No valid line "order number;tracking code" found.'], $this->flashes($crawler));
    }

    /** @return list<ShipmentInterface> */
    private function readyShipmentsOfDistinctOrders(int $count): array
    {
        /** @var list<ShipmentInterface> $candidates */
        $candidates = $this->entityManager()->createQueryBuilder()
            ->select('s')
            ->from(ShipmentInterface::class, 's')
            ->innerJoin('s.order', 'o')
            ->andWhere('s.state = :state')
            ->andWhere('o.state = :orderState')
            ->setParameter('state', ShipmentInterface::STATE_READY)
            ->setParameter('orderState', OrderInterface::STATE_NEW)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        $shipments = [];
        foreach ($candidates as $shipment) {
            $shipments[(string) $shipment->getOrder()?->getNumber()] ??= $shipment;
        }
        if (count($shipments) < $count) {
            self::markTestSkipped('The random fixtures do not contain enough orders ready to ship.');
        }

        return array_slice(array_values($shipments), 0, $count);
    }
}
