<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Action;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductTaxon;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\AddProductsToChannelsAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\AddProductsToTaxonAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\RemoveProductsFromChannelsAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\RemoveProductsFromTaxonAction;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\AddProductsToTaxonType;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\ProductChannelsType;

final class ProductTaxonAndChannelActionsTest extends TestCase
{
    public function testItAddsAProductToATaxon(): void
    {
        $taxon = new Taxon();
        $product = new Product();

        $result = $this->addToTaxon()->execute($product, ['taxon' => $taxon, 'main_taxon' => false]);

        self::assertTrue($result->isApplied());
        self::assertTrue($product->hasTaxon($taxon));
        self::assertNull($product->getMainTaxon());
    }

    public function testItAlsoSetsTheMainTaxonWhenAsked(): void
    {
        $taxon = new Taxon();
        $product = new Product();

        $this->addToTaxon()->execute($product, ['taxon' => $taxon, 'main_taxon' => true]);

        self::assertSame($taxon, $product->getMainTaxon());
    }

    public function testItSetsOnlyTheMainTaxonOfAProductAlreadyInTheTaxon(): void
    {
        $taxon = new Taxon();
        $product = $this->productInTaxon($taxon);

        $result = $this->addToTaxon()->execute($product, ['taxon' => $taxon, 'main_taxon' => true]);

        self::assertTrue($result->isApplied());
        self::assertCount(1, $product->getProductTaxons());
        self::assertSame($taxon, $product->getMainTaxon());
    }

    public function testItSkipsAProductAlreadyInTheTaxon(): void
    {
        $taxon = new Taxon();
        $product = $this->productInTaxon($taxon);

        $result = $this->addToTaxon()->execute($product, ['taxon' => $taxon, 'main_taxon' => false]);

        self::assertTrue($result->isSkipped());
        self::assertSame('tmonier_bulk_actions.skip.already_in_taxon', $result->getReason());
        self::assertSame(AddProductsToTaxonType::class, $this->addToTaxon()->getFormType());
    }

    public function testItRemovesAProductFromATaxonAndUnsetsTheMainTaxon(): void
    {
        $taxon = new Taxon();
        $product = $this->productInTaxon($taxon);
        $product->setMainTaxon($taxon);

        $result = (new RemoveProductsFromTaxonAction(new RecordingResourceEventDispatcher()))->execute($product, ['taxon' => $taxon]);

        self::assertTrue($result->isApplied());
        self::assertFalse($product->hasTaxon($taxon));
        self::assertNull($product->getMainTaxon());
    }

    public function testItSkipsAProductNotInTheTaxon(): void
    {
        $result = (new RemoveProductsFromTaxonAction(new RecordingResourceEventDispatcher()))->execute(new Product(), ['taxon' => new Taxon()]);

        self::assertSame('tmonier_bulk_actions.skip.not_in_taxon', $result->getReason());
    }

    public function testItAddsAProductToTheChannelsWhereEveryVariantHasAPrice(): void
    {
        $channel = $this->channel('WEB');
        $product = $this->productWithVariantPricedIn($channel);

        $result = $this->addToChannels()->execute($product, ['channels' => new ArrayCollection([$channel])]);

        self::assertTrue($result->isApplied());
        self::assertTrue($product->hasChannel($channel));
    }

    public function testItSkipsAProductWithAVariantWithoutPriceInTheChannel(): void
    {
        $priced = $this->channel('WEB');
        $unpriced = $this->channel('B2B');
        $product = $this->productWithVariantPricedIn($priced);

        $result = $this->addToChannels()->execute($product, ['channels' => [$priced, $unpriced]]);

        self::assertTrue($result->isSkipped());
        self::assertSame('tmonier_bulk_actions.skip.missing_channel_pricing', $result->getReason());
        self::assertSame(['%variant%' => 'VARIANT', '%channel%' => 'B2B channel'], $result->getParameters());
        self::assertFalse($product->hasChannel($priced), 'Nothing changes when one channel cannot be added.');
    }

    public function testItSkipsAProductAlreadyInTheChannels(): void
    {
        $channel = $this->channel('WEB');
        $product = $this->productWithVariantPricedIn($channel);
        $product->addChannel($channel);

        self::assertSame('tmonier_bulk_actions.skip.already_in_channels', $this->addToChannels()->execute($product, ['channels' => [$channel]])->getReason());
    }

    public function testItRemovesAProductFromChannels(): void
    {
        $channel = $this->channel('WEB');
        $product = new Product();
        $product->addChannel($channel);
        $action = new RemoveProductsFromChannelsAction(new RecordingResourceEventDispatcher());

        self::assertTrue($action->execute($product, ['channels' => [$channel]])->isApplied());
        self::assertFalse($product->hasChannel($channel));
        self::assertSame('tmonier_bulk_actions.skip.not_in_channels', $action->execute($product, ['channels' => [$channel]])->getReason());
    }

    public function testTheChannelsHelperIgnoresInvalidData(): void
    {
        self::assertSame([], ProductChannelsType::channels([]));
        self::assertSame([], ProductChannelsType::channels(['channels' => 'WEB']));
        self::assertSame([], ProductChannelsType::channels(['channels' => ['WEB']]));
    }

    private function addToTaxon(): AddProductsToTaxonAction
    {
        $factory = $this->createMock(FactoryInterface::class);
        $factory->method('createNew')->willReturnCallback(static fn (): ProductTaxon => new ProductTaxon());

        return new AddProductsToTaxonAction($factory, new RecordingResourceEventDispatcher());
    }

    private function addToChannels(): AddProductsToChannelsAction
    {
        return new AddProductsToChannelsAction(new RecordingResourceEventDispatcher());
    }

    private function productInTaxon(Taxon $taxon): Product
    {
        $product = new Product();
        $productTaxon = new ProductTaxon();
        $productTaxon->setTaxon($taxon);
        $productTaxon->setProduct($product);
        self::assertInstanceOf(ProductTaxonInterface::class, $productTaxon);
        $product->addProductTaxon($productTaxon);

        return $product;
    }

    private function channel(string $code): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);
        $channel->setName($code . ' channel');

        return $channel;
    }

    private function productWithVariantPricedIn(Channel $channel): Product
    {
        $product = new Product();
        $variant = new ProductVariant();
        $variant->setCode('VARIANT');
        $pricing = new ChannelPricing();
        $pricing->setChannelCode($channel->getCode());
        $variant->addChannelPricing($pricing);
        $product->addVariant($variant);

        return $product;
    }
}
