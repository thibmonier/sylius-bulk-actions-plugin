<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Form\Type;

use Sylius\Bundle\CustomerBundle\Form\Type\CustomerGroupChoiceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

final class AssignCustomerGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('group', CustomerGroupChoiceType::class, [
            'label' => 'tmonier_bulk_actions.form.customer_group',
            'placeholder' => 'tmonier_bulk_actions.form.choose',
            'constraints' => [new NotBlank()],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_bulk_actions_assign_customer_group';
    }
}
