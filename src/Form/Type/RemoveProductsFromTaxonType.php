<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Form\Type;

use Sylius\Bundle\AdminBundle\Form\Type\TaxonAutocompleteType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class RemoveProductsFromTaxonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('taxon', TaxonAutocompleteType::class, [
            'label' => 'tmonier_bulk_actions.form.taxon',
            'constraints' => [new NotBlank()],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_bulk_actions_remove_products_from_taxon';
    }
}
