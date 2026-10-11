<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Functional;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminProductBulkActionsTest extends WebTestCase
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

    public function testTheProductGridOffersTheBulkActions(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/products/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-tmonier-bulk-actions]'));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-bulk-action="product_disable"]'));
        self::assertGreaterThan(0, $crawler->filter('input[data-check-all-group="index"]')->count());
    }

    public function testItDisablesProductsAndReportsTheSkippedOnes(): void
    {
        $this->loginAdmin();
        [$first, $second, $third] = $this->products(3);
        $first->setEnabled(true);
        $second->setEnabled(true);
        $third->setEnabled(false);
        $this->entityManager()->flush();

        $crawler = $this->runBulkAction('product_disable', [$this->idOf($first), $this->idOf($second), $this->idOf($third)], [], '/admin/products/');

        self::assertSame(['Disable: 2 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        self::assertSame([sprintf('%s (%s): Already disabled.', $third->getName(), $third->getCode())], $this->reportRows($crawler, 'skipped'));
        $this->entityManager()->clear();
        self::assertFalse($this->reload($first)->isEnabled());
        self::assertFalse($this->reload($second)->isEnabled());
    }

    public function testItAddsProductsToATaxonAndOptionallyAsMainTaxon(): void
    {
        $this->loginAdmin();
        $taxon = $this->taxon();
        [$outside, $inside] = $this->products(2);
        $this->removeFromTaxon($outside, $taxon);
        $this->addToTaxon($inside, $taxon);
        $inside->setMainTaxon($taxon);
        $this->entityManager()->flush();

        $crawler = $this->runBulkAction('product_add_taxon', [$this->idOf($outside), $this->idOf($inside)], [
            'taxon' => (string) $taxon->getId(),
            'main_taxon' => '1',
        ], '/admin/products/');

        self::assertSame(['Add to a taxon: 1 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        self::assertSame([sprintf('%s (%s): Already in this taxon.', $inside->getName(), $inside->getCode())], $this->reportRows($crawler, 'skipped'));
        $this->entityManager()->clear();
        $outside = $this->reload($outside);
        self::assertTrue($outside->hasTaxon($this->reloadTaxon($taxon)));
        self::assertSame($taxon->getId(), $outside->getMainTaxon()?->getId());
    }

    public function testItRemovesProductsFromATaxonAndUnsetsTheMainTaxon(): void
    {
        $this->loginAdmin();
        $taxon = $this->taxon();
        [$inside, $outside] = $this->products(2);
        $this->addToTaxon($inside, $taxon);
        $inside->setMainTaxon($taxon);
        $this->removeFromTaxon($outside, $taxon);
        if ($outside->getMainTaxon() === $taxon) {
            $outside->setMainTaxon(null);
        }
        $this->entityManager()->flush();

        $crawler = $this->runBulkAction('product_remove_taxon', [$this->idOf($inside), $this->idOf($outside)], [
            'taxon' => (string) $taxon->getId(),
        ], '/admin/products/');

        self::assertSame(['Remove from a taxon: 1 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        $this->entityManager()->clear();
        $inside = $this->reload($inside);
        self::assertFalse($inside->hasTaxon($this->reloadTaxon($taxon)));
        self::assertNull($inside->getMainTaxon());
    }

    public function testTheTaxonIsRequired(): void
    {
        $this->loginAdmin();
        [$product] = $this->products(1);

        $crawler = $this->openConfirmation('product_add_taxon', [$this->idOf($product)], '/admin/products/');
        $form = $crawler->filter('[data-test-tmonier-bulk-action-submit]')->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues());

        self::assertResponseStatusCodeSame(422);
    }

    public function testItAddsProductsToChannelsOnlyWhenEveryVariantHasAPriceThere(): void
    {
        $this->loginAdmin();
        $channel = $this->channel();
        [$priced, $unpriced] = $this->products(2);
        $priced->removeChannel($channel);
        $unpriced->removeChannel($channel);
        $variant = $unpriced->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $pricing = $variant->getChannelPricingForChannel($channel);
        self::assertNotNull($pricing);
        $variant->removeChannelPricing($pricing);
        $this->entityManager()->flush();

        $crawler = $this->runBulkAction('product_add_channels', [$this->idOf($priced), $this->idOf($unpriced)], [
            'channels' => [(string) $channel->getCode()],
        ], '/admin/products/');

        self::assertSame(['Add to channels: 1 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        self::assertSame([sprintf(
            '%s (%s): The variant %s has no price in the channel %s.',
            $unpriced->getName(),
            $unpriced->getCode(),
            $variant->getCode(),
            $channel->getName(),
        )], $this->reportRows($crawler, 'skipped'));
        $this->entityManager()->clear();
        self::assertTrue($this->reload($priced)->hasChannel($this->reloadChannel($channel)));
        self::assertFalse($this->reload($unpriced)->hasChannel($this->reloadChannel($channel)));
    }

    public function testItRemovesProductsFromChannels(): void
    {
        $this->loginAdmin();
        $channel = $this->channel();
        [$inside, $outside] = $this->products(2);
        $inside->addChannel($channel);
        $outside->removeChannel($channel);
        $this->entityManager()->flush();

        $crawler = $this->runBulkAction('product_remove_channels', [$this->idOf($inside), $this->idOf($outside)], [
            'channels' => [(string) $channel->getCode()],
        ], '/admin/products/');

        self::assertSame(['Remove from channels: 1 updated, 1 skipped, 0 failed.'], $this->flashes($crawler));
        $this->entityManager()->clear();
        self::assertFalse($this->reload($inside)->hasChannel($this->reloadChannel($channel)));
    }

    public function testItEnablesAndDisablesProductVariantsFromTheVariantGrid(): void
    {
        $this->loginAdmin();
        [$product] = $this->products(1);
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $variant->setEnabled(true);
        $this->entityManager()->flush();
        $gridUrl = sprintf('/admin/products/%d/variants/', $this->idOf($product));

        $crawler = $this->client->request('GET', $gridUrl);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-tmonier-bulk-action="product_variant_disable"]'));

        $crawler = $this->runBulkAction('product_variant_disable', [$this->idOf($variant)], [], $gridUrl);

        self::assertSame(['Disable: 1 updated, 0 skipped, 0 failed.'], $this->flashes($crawler));
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(ProductVariantInterface::class, $this->idOf($variant));
        self::assertInstanceOf(ProductVariantInterface::class, $reloaded);
        self::assertFalse($reloaded->isEnabled());
    }

    private function taxon(): TaxonInterface
    {
        /** @var TaxonInterface|null $taxon */
        $taxon = $this->entityManager()->getRepository(TaxonInterface::class)->createQueryBuilder('t')
            ->andWhere('t.parent IS NOT NULL')
            ->orderBy('t.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
        self::assertInstanceOf(TaxonInterface::class, $taxon);

        return $taxon;
    }

    private function addToTaxon(ProductInterface $product, TaxonInterface $taxon): void
    {
        if ($product->hasTaxon($taxon)) {
            return;
        }

        /** @var FactoryInterface<ProductTaxonInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.product_taxon');
        $productTaxon = $factory->createNew();
        $productTaxon->setProduct($product);
        $productTaxon->setTaxon($taxon);
        $product->addProductTaxon($productTaxon);
    }

    private function removeFromTaxon(ProductInterface $product, TaxonInterface $taxon): void
    {
        foreach ($product->getProductTaxons() as $productTaxon) {
            if ($productTaxon->getTaxon() === $taxon) {
                $product->removeProductTaxon($productTaxon);
            }
        }
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(ChannelInterface::class, $channel);

        return $channel;
    }

    private function reloadTaxon(TaxonInterface $taxon): TaxonInterface
    {
        $reloaded = $this->entityManager()->find(TaxonInterface::class, $this->idOf($taxon));
        self::assertInstanceOf(TaxonInterface::class, $reloaded);

        return $reloaded;
    }

    private function reloadChannel(ChannelInterface $channel): ChannelInterface
    {
        $reloaded = $this->entityManager()->find(ChannelInterface::class, $this->idOf($channel));
        self::assertInstanceOf(ChannelInterface::class, $reloaded);

        return $reloaded;
    }

    /** @return list<ProductInterface> */
    private function products(int $count): array
    {
        /** @var list<ProductInterface> $products */
        $products = $this->entityManager()->getRepository(ProductInterface::class)->findBy([], ['id' => 'ASC'], $count);
        self::assertCount($count, $products);

        return $products;
    }

    private function reload(ProductInterface $product): ProductInterface
    {
        $reloaded = $this->entityManager()->find(ProductInterface::class, $this->idOf($product));
        self::assertInstanceOf(ProductInterface::class, $reloaded);

        return $reloaded;
    }
}
