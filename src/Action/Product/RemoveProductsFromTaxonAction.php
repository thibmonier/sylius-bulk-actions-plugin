<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action\Product;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\RemoveProductsFromTaxonType;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;
use Webmozart\Assert\Assert;

/** Removes the products from a taxon; the main taxon is unset when it is this taxon. */
final class RemoveProductsFromTaxonAction implements BulkActionInterface
{
    public function __construct(private readonly ResourceEventDispatcherInterface $resourceEventDispatcher)
    {
    }

    public function getCode(): string
    {
        return 'product_remove_taxon';
    }

    public function getResource(): string
    {
        return 'sylius.product';
    }

    public function getLabel(): string
    {
        return 'tmonier_bulk_actions.action.product_remove_taxon';
    }

    public function getIcon(): string
    {
        return 'tabler:folder-minus';
    }

    public function getFormType(): string
    {
        return RemoveProductsFromTaxonType::class;
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

        $productTaxon = $item->getProductTaxons()->filter(
            static fn ($productTaxon): bool => $productTaxon->getTaxon() === $taxon,
        )->first();
        $isMainTaxon = $item->getMainTaxon() === $taxon;
        if (false === $productTaxon && !$isMainTaxon) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.not_in_taxon');
        }

        $stopped = $this->resourceEventDispatcher->dispatchPreEvent('sylius.product', 'update', $item);
        if (null !== $stopped) {
            return $stopped;
        }

        if (false !== $productTaxon) {
            $item->removeProductTaxon($productTaxon);
        }
        if ($isMainTaxon) {
            $item->setMainTaxon(null);
        }

        return BulkActionResult::applied($this->resourceEventDispatcher->postEventCallback('sylius.product', 'update', $item));
    }
}
