<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests run against the test application loaded with the Sylius fixtures. The kernel is not
 * rebooted between requests and every test runs in a transaction rolled back at the end, so
 * that cancelled orders or disabled products do not leak into the next test. The fixtures are
 * random: each test creates or puts in the right state the data it needs.
 */
trait BulkActionsTestTrait
{
    private KernelBrowser $client;

    private function setUpClient(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection()->beginTransaction();
    }

    private function tearDownClient(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
    }

    private function loginAdmin(string $username = 'sylius'): AdminUserInterface
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }

    /**
     * Posts the grid selection, submits the confirmation form with the given action data and
     * follows the redirection to the grid.
     *
     * @param list<int> $ids
     * @param array<string, mixed> $data fields of the action form, e.g. ['taxon' => 12, 'main_taxon' => '1']
     */
    private function runBulkAction(string $code, array $ids, array $data = [], string $gridUrl = '/admin/'): Crawler
    {
        $this->submitBulkAction($code, $ids, $data, $gridUrl);

        return $this->client->followRedirect();
    }

    /**
     * Same as runBulkAction() without following the redirection, so that the e-mails sent by
     * the execution request can be checked.
     *
     * @param list<int> $ids
     * @param array<string, mixed> $data
     */
    private function submitBulkAction(string $code, array $ids, array $data = [], string $gridUrl = '/admin/'): void
    {
        $crawler = $this->openConfirmation($code, $ids, $gridUrl);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('[data-test-tmonier-bulk-action-submit]')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['tmonier_bulk_actions']);
        if ([] !== $data) {
            $values['tmonier_bulk_actions']['data'] = $data;
        }

        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects($gridUrl);
    }

    /** @param list<int> $ids */
    private function openConfirmation(string $code, array $ids, string $gridUrl = '/admin/'): Crawler
    {
        return $this->client->request('POST', sprintf('/admin/bulk-actions/%s', $code), ['ids' => array_map('strval', $ids)], [], [
            'HTTP_REFERER' => 'http://localhost' . $gridUrl,
        ]);
    }

    /** @return list<string> */
    private function flashes(Crawler $crawler): array
    {
        /** @var list<string> $messages */
        $messages = $crawler->filter('[data-test-sylius-flash-message] .alert-description')->each(static fn (Crawler $node): string => trim($node->text()));

        return $messages;
    }

    /** @return list<string> "label: reason" of the report rows */
    private function reportRows(Crawler $crawler, string $status): array
    {
        /** @var list<string> $rows */
        $rows = $crawler->filter(sprintf('[data-test-tmonier-bulk-actions-report-item="%s"]', $status))->each(
            static fn (Crawler $node): string => trim($node->filter('td')->eq(0)->text()) . ': ' . trim($node->filter('td')->eq(2)->text()),
        );

        return $rows;
    }

    private function idOf(object $resource): int
    {
        self::assertTrue(method_exists($resource, 'getId'));
        $id = $resource->getId();
        self::assertIsInt($id);

        return $id;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
