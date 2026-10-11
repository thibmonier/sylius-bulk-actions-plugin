<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_bulk_actions');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('max_items')
                    ->info('Maximum number of items processed by one bulk request.')
                    ->defaultValue(500)
                    ->min(1)
                ->end()
                ->integerNode('batch_size')
                    ->info('Number of items changed between two flushes of the entity manager.')
                    ->defaultValue(50)
                    ->min(1)
                ->end()
                ->arrayNode('actions')
                    ->info('Enable or disable bulk actions by code (all registered actions are enabled by default).')
                    ->useAttributeAsKey('code')
                    ->arrayPrototype()
                        ->canBeDisabled()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
