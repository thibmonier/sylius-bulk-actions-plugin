<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Confirmation form of a bulk action: the selected ids, the page to go back to and, under
 * "data", the form type of the action. CSRF protected.
 */
final class BulkActionType extends AbstractType
{
    public const CSRF_TOKEN_ID = 'tmonier_bulk_actions';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ids', HiddenType::class)
            ->add('redirect', HiddenType::class)
        ;

        /** @var class-string<FormTypeInterface>|null $dataType */
        $dataType = $options['data_type'];
        if (null !== $dataType) {
            $builder->add('data', $dataType, ['label' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_type' => null,
            'csrf_protection' => true,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
        ]);
        $resolver->setAllowedTypes('data_type', ['null', 'string']);
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_bulk_actions';
    }
}
