<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Form\Type;

use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Count;

/** @extends AbstractType<array<string, mixed>> */
final class ProductChannelsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('channels', ChannelChoiceType::class, [
            'label' => 'tmonier_bulk_actions.form.channels',
            'multiple' => true,
            'expanded' => true,
            'constraints' => [new Count(min: 1)],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<ChannelInterface>
     */
    public static function channels(array $data): array
    {
        $channels = $data['channels'] ?? [];
        if (!is_iterable($channels)) {
            return [];
        }

        $result = [];
        foreach ($channels as $channel) {
            if ($channel instanceof ChannelInterface) {
                $result[] = $channel;
            }
        }

        return $result;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_bulk_actions_product_channels';
    }
}
