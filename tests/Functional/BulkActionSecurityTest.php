<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Functional;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BulkActionSecurityTest extends WebTestCase
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

    public function testAnInvalidCsrfTokenIsRejected(): void
    {
        $this->loginAdmin();
        $product = $this->enabledProduct();

        $crawler = $this->openConfirmation('product_disable', [$this->idOf($product)], '/admin/products/');
        $form = $crawler->filter('[data-test-tmonier-bulk-action-submit]')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['tmonier_bulk_actions']);
        $values['tmonier_bulk_actions']['_token'] = 'forged';
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        $this->entityManager()->clear();
        self::assertTrue($this->reloadProduct($product)->isEnabled());
    }

    public function testAnonymousVisitorsAreSentToTheLoginPage(): void
    {
        $product = $this->enabledProduct();

        $this->client->request('POST', '/admin/bulk-actions/product_disable/execute', [
            'tmonier_bulk_actions' => ['ids' => (string) $this->idOf($product), 'redirect' => '/admin/'],
        ]);

        self::assertResponseRedirects('/admin/login');
        $this->entityManager()->clear();
        self::assertTrue($this->reloadProduct($product)->isEnabled());
    }

    public function testShopCustomersCannotUseBulkActions(): void
    {
        $shopUser = $this->entityManager()->getRepository(ShopUserInterface::class)->findOneBy([]);
        self::assertInstanceOf(ShopUserInterface::class, $shopUser);
        $this->client->loginUser($shopUser, 'shop');
        $product = $this->enabledProduct();

        $this->client->request('POST', '/admin/bulk-actions/product_disable', ['ids' => [(string) $this->idOf($product)]]);

        self::assertResponseRedirects('/admin/login');
    }

    public function testTheNumberOfItemsIsCapped(): void
    {
        $this->loginAdmin();
        $ids = array_map('strval', range(1, 501));

        $this->client->request('POST', '/admin/bulk-actions/product_disable', ['ids' => $ids], [], ['HTTP_REFERER' => 'http://localhost/admin/products/']);

        self::assertResponseRedirects('/admin/products/');
        $crawler = $this->client->followRedirect();
        self::assertSame(['501 items selected: a bulk action processes 500 items at most. Select fewer items and try again.'], $this->flashes($crawler));
    }

    public function testAnEmptySelectionIsRefused(): void
    {
        $this->loginAdmin();

        $this->client->request('POST', '/admin/bulk-actions/product_disable', [], [], ['HTTP_REFERER' => 'http://localhost/admin/products/']);

        self::assertResponseRedirects('/admin/products/');
        self::assertSame(['Select at least one item.'], $this->flashes($this->client->followRedirect()));
    }

    public function testAnUnknownActionIsNotFound(): void
    {
        $this->loginAdmin();

        $this->client->request('POST', '/admin/bulk-actions/delete_everything', ['ids' => ['1']]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheRedirectionStaysOnTheAdmin(): void
    {
        $this->loginAdmin();
        $product = $this->enabledProduct();

        $this->client->request('POST', '/admin/bulk-actions/product_disable', ['ids' => [(string) $this->idOf($product)]], [], [
            'HTTP_REFERER' => 'https://evil.example.com/phishing',
        ]);
        $crawler = $this->client->getCrawler();
        self::assertSame('/admin/', $crawler->filter('#tmonier_bulk_actions_redirect')->attr('value'));
    }

    private function enabledProduct(): ProductInterface
    {
        $product = $this->entityManager()->getRepository(ProductInterface::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(ProductInterface::class, $product);
        $product->setEnabled(true);
        $this->entityManager()->flush();

        return $product;
    }

    private function reloadProduct(ProductInterface $product): ProductInterface
    {
        $reloaded = $this->entityManager()->find(ProductInterface::class, $this->idOf($product));
        self::assertInstanceOf(ProductInterface::class, $reloaded);

        return $reloaded;
    }
}
