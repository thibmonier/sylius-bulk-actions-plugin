<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ShipWithTrackingCodesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('lines', TextareaType::class, [
            'label' => 'tmonier_bulk_actions.form.tracking_codes',
            'help' => 'tmonier_bulk_actions.ui.tracking_codes_help',
            'constraints' => [new NotBlank()],
            'attr' => ['rows' => 12, 'placeholder' => "000000042;1Z999AA10123456784\n000000043;1Z999AA10123456785"],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => BulkActionType::CSRF_TOKEN_ID,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_bulk_actions_ship_with_tracking_codes';
    }
}
