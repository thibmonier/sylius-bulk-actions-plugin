<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Functional;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCustomerBulkActionsTest extends WebTestCase
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

    public function testTheCustomerGridOffersTheBulkActions(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/customers/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-tmonier-bulk-action="customer_assign_group"]'));
    }

    public function testItAssignsCustomersToAGroup(): void
    {
        $this->loginAdmin();
        $group = $this->createGroup('BULK_VIP');
        [$first, $second] = $this->customers(2);
        $first->setGroup(null);
        $second->setGroup($group);
        $this->entityManager()->flush();

        $crawler = $this->runBulkAction('customer_assign_group', [$this->idOf($first), $this->idOf($second)], [
            'group' => 'BULK_VIP',
        ], '/admin/customers/');

        self::assertSame(['Assign to a group: 1 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        self::assertSame([sprintf('%s: Already in this group.', $second->getEmail())], $this->reportRows($crawler, 'skipped'));
        $this->entityManager()->clear();
        self::assertSame('BULK_VIP', $this->reload($first)->getGroup()?->getCode());
    }

    public function testItRemovesCustomersFromTheirGroup(): void
    {
        $this->loginAdmin();
        $group = $this->createGroup('BULK_VIP');
        [$first, $second] = $this->customers(2);
        $first->setGroup($group);
        $second->setGroup(null);
        $this->entityManager()->flush();

        $crawler = $this->runBulkAction('customer_remove_group', [$this->idOf($first), $this->idOf($second)], [], '/admin/customers/');

        self::assertSame(['Remove from their group: 1 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        $this->entityManager()->clear();
        self::assertNull($this->reload($first)->getGroup());
    }

    public function testItDisablesShopAccountsAndSkipsGuests(): void
    {
        $this->loginAdmin();
        $withAccount = $this->customers(1, true)[0];
        $user = $withAccount->getUser();
        self::assertNotNull($user);
        $user->setEnabled(true);
        $guest = $this->createGuest('bulk.guest@example.com');

        $crawler = $this->runBulkAction('customer_disable_account', [$this->idOf($withAccount), $this->idOf($guest)], [], '/admin/customers/');

        self::assertSame(['Disable account: 1 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        self::assertSame([sprintf('%s: Guest customer without a shop account.', $guest->getEmail())], $this->reportRows($crawler, 'skipped'));
        $this->entityManager()->clear();
        self::assertFalse((bool) $this->reload($withAccount)->getUser()?->isEnabled());
    }

    private function createGroup(string $code): CustomerGroupInterface
    {
        /** @var FactoryInterface<CustomerGroupInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.customer_group');
        $group = $factory->createNew();
        $group->setCode($code);
        $group->setName('Bulk VIP');
        $this->entityManager()->persist($group);
        $this->entityManager()->flush();

        return $group;
    }

    private function createGuest(string $email): CustomerInterface
    {
        /** @var FactoryInterface<CustomerInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.customer');
        $customer = $factory->createNew();
        $customer->setEmail($email);
        $this->entityManager()->persist($customer);
        $this->entityManager()->flush();

        return $customer;
    }

    /** @return list<CustomerInterface> */
    private function customers(int $count, bool $withAccount = false): array
    {
        $queryBuilder = $this->entityManager()->createQueryBuilder()
            ->select('c')
            ->from(CustomerInterface::class, 'c')
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($count)
        ;
        if ($withAccount) {
            $queryBuilder->innerJoin('c.user', 'u');
        }

        /** @var list<CustomerInterface> $customers */
        $customers = $queryBuilder->getQuery()->getResult();
        if (count($customers) < $count) {
            self::markTestSkipped('The random fixtures do not contain the customers this test needs.');
        }

        return $customers;
    }

    private function reload(CustomerInterface $customer): CustomerInterface
    {
        $reloaded = $this->entityManager()->find(CustomerInterface::class, $this->idOf($customer));
        self::assertInstanceOf(CustomerInterface::class, $reloaded);

        return $reloaded;
    }
}
