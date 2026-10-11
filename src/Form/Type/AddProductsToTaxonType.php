<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Form\Type;

use Sylius\Bundle\AdminBundle\Form\Type\TaxonAutocompleteType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class AddProductsToTaxonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('taxon', TaxonAutocompleteType::class, [
                'label' => 'tmonier_bulk_actions.form.taxon',
                'constraints' => [new NotBlank()],
            ])
            ->add('main_taxon', CheckboxType::class, [
                'label' => 'tmonier_bulk_actions.form.main_taxon',
                'required' => false,
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_bulk_actions_add_products_to_taxon';
    }
}
