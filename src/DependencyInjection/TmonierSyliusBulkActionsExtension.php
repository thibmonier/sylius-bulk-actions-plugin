<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;

final class TmonierSyliusBulkActionsExtension extends Extension
{
    public const ACTION_TAG = 'tmonier_bulk_actions.action';

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{max_items: int, batch_size: int, actions: array<string, array{enabled: bool}>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $disabledActions = [];
        foreach ($config['actions'] as $code => $action) {
            if (!$action['enabled']) {
                $disabledActions[] = (string) $code;
            }
        }

        $container->setParameter('tmonier_bulk_actions.max_items', $config['max_items']);
        $container->setParameter('tmonier_bulk_actions.batch_size', $config['batch_size']);
        $container->setParameter('tmonier_bulk_actions.disabled_actions', $disabledActions);

        $container->registerForAutoconfiguration(BulkActionInterface::class)->addTag(self::ACTION_TAG);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }

    public function getAlias(): string
    {
        return 'tmonier_sylius_bulk_actions';
    }
}
