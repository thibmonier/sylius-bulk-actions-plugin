<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Product;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\AddProductsToTaxonType;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;
use Webmozart\Assert\Assert;

final class AddProductsToTaxonAction implements BulkActionInterface
{
    /** @param FactoryInterface<ProductTaxonInterface> $productTaxonFactory */
    public function __construct(
        private readonly FactoryInterface $productTaxonFactory,
        private readonly ResourceEventDispatcherInterface $resourceEventDispatcher,
    ) {
    }

    public function getCode(): string
    {
        return 'product_add_taxon';
    }

    public function getResource(): string
    {
        return 'sylius.product';
    }

    public function getLabel(): string
    {
        return 'tmonier_bulk_actions.action.product_add_taxon';
    }

    public function getIcon(): string
    {
        return 'tabler:folder-plus';
    }

    public function getFormType(): ?string
    {
        return AddProductsToTaxonType::class;
    }

    public function supports(object $item): bool
    {
        return $item instanceof ProductInterface;
    }

    public function execute(object $item, array $data): BulkActionResult
    {
        Assert::isInstanceOf($item, ProductInterface::class);
        $taxon = $data['taxon'] ?? null;
        Assert::isInstanceOf($taxon, TaxonInterface::class);
        $setMainTaxon = true === ($data['main_taxon'] ?? false);

        $addTaxon = !$item->hasTaxon($taxon);
        $changeMainTaxon = $setMainTaxon && $item->getMainTaxon() !== $taxon;
        if (!$addTaxon && !$changeMainTaxon) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.already_in_taxon');
        }

        $stopped = $this->resourceEventDispatcher->dispatchPreEvent('sylius.product', 'update', $item);
        if (null !== $stopped) {
            return $stopped;
        }

        if ($addTaxon) {
            $productTaxon = $this->productTaxonFactory->createNew();
            $productTaxon->setProduct($item);
            $productTaxon->setTaxon($taxon);
            $item->addProductTaxon($productTaxon);
        }
        if ($changeMainTaxon) {
            $item->setMainTaxon($taxon);
        }

        return BulkActionResult::applied($this->resourceEventDispatcher->postEventCallback('sylius.product', 'update', $item));
    }
}
