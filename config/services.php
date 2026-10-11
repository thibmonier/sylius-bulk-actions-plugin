<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusBulkActionsPlugin\Action\Customer\AssignCustomerGroupAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Customer\RemoveCustomerGroupAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Customer\ToggleCustomerAccountAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Order\CancelOrderAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Order\CompleteOrderPaymentAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Payment\CompletePaymentAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\AddProductsToChannelsAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\AddProductsToTaxonAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\RemoveProductsFromChannelsAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\RemoveProductsFromTaxonAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\ToggleProductAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Product\ToggleProductVariantAction;
use Tmonier\SyliusBulkActionsPlugin\Action\Shipment\ShipShipmentAction;
use Tmonier\SyliusBulkActionsPlugin\Controller\Admin\BulkActionController;
use Tmonier\SyliusBulkActionsPlugin\DependencyInjection\TmonierSyliusBulkActionsExtension;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\AddProductsToTaxonType;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\AssignCustomerGroupType;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\BulkActionType;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\ProductChannelsType;
use Tmonier\SyliusBulkActionsPlugin\Form\Type\RemoveProductsFromTaxonType;
use Tmonier\SyliusBulkActionsPlugin\Processor\BulkActionProcessor;
use Tmonier\SyliusBulkActionsPlugin\Processor\BulkActionProcessorInterface;
use Tmonier\SyliusBulkActionsPlugin\Processor\ItemLabeler;
use Tmonier\SyliusBulkActionsPlugin\Processor\ItemLabelerInterface;
use Tmonier\SyliusBulkActionsPlugin\Registry\BulkActionRegistry;
use Tmonier\SyliusBulkActionsPlugin\Registry\BulkActionRegistryInterface;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcher;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;
use Tmonier\SyliusBulkActionsPlugin\StateMachine\TransitionApplier;
use Tmonier\SyliusBulkActionsPlugin\StateMachine\TransitionApplierInterface;
use Tmonier\SyliusBulkActionsPlugin\Twig\BulkActionsExtension;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $tag = TmonierSyliusBulkActionsExtension::ACTION_TAG;

    // Infrastructure
    $services
        ->set('tmonier_bulk_actions.resource_event_dispatcher', ResourceEventDispatcher::class)
        ->args([service('event_dispatcher')])
    ;
    $services->alias(ResourceEventDispatcherInterface::class, 'tmonier_bulk_actions.resource_event_dispatcher');

    $services
        ->set('tmonier_bulk_actions.transition_applier', TransitionApplier::class)
        ->args([
            service('sylius_abstraction.state_machine'),
            service('tmonier_bulk_actions.resource_event_dispatcher'),
        ])
    ;
    $services->alias(TransitionApplierInterface::class, 'tmonier_bulk_actions.transition_applier');

    $services
        ->set('tmonier_bulk_actions.registry', BulkActionRegistry::class)
        ->args([
            tagged_iterator($tag),
            param('tmonier_bulk_actions.disabled_actions'),
        ])
    ;
    $services->alias(BulkActionRegistryInterface::class, 'tmonier_bulk_actions.registry');

    $services->set('tmonier_bulk_actions.item_labeler', ItemLabeler::class);
    $services->alias(ItemLabelerInterface::class, 'tmonier_bulk_actions.item_labeler');

    $services
        ->set('tmonier_bulk_actions.processor', BulkActionProcessor::class)
        ->args([
            service('sylius.resource_registry'),
            service('doctrine'),
            service('event_dispatcher'),
            service('tmonier_bulk_actions.item_labeler'),
            service('logger'),
            param('tmonier_bulk_actions.max_items'),
            param('tmonier_bulk_actions.batch_size'),
        ])
        ->tag('monolog.logger', ['channel' => 'tmonier_bulk_actions'])
    ;
    $services->alias(BulkActionProcessorInterface::class, 'tmonier_bulk_actions.processor');

    // Products
    foreach (['enable' => true, 'disable' => false] as $name => $enable) {
        $services
            ->set('tmonier_bulk_actions.action.product_' . $name, ToggleProductAction::class)
            ->args([$enable, service('tmonier_bulk_actions.resource_event_dispatcher')])
            ->tag($tag)
        ;
        $services
            ->set('tmonier_bulk_actions.action.product_variant_' . $name, ToggleProductVariantAction::class)
            ->args([$enable, service('tmonier_bulk_actions.resource_event_dispatcher')])
            ->tag($tag)
        ;
    }

    $services
        ->set('tmonier_bulk_actions.action.product_add_taxon', AddProductsToTaxonAction::class)
        ->args([
            service('sylius.factory.product_taxon'),
            service('tmonier_bulk_actions.resource_event_dispatcher'),
        ])
        ->tag($tag)
    ;
    $services
        ->set('tmonier_bulk_actions.action.product_remove_taxon', RemoveProductsFromTaxonAction::class)
        ->args([service('tmonier_bulk_actions.resource_event_dispatcher')])
        ->tag($tag)
    ;
    $services
        ->set('tmonier_bulk_actions.action.product_add_channels', AddProductsToChannelsAction::class)
        ->args([service('tmonier_bulk_actions.resource_event_dispatcher')])
        ->tag($tag)
    ;
    $services
        ->set('tmonier_bulk_actions.action.product_remove_channels', RemoveProductsFromChannelsAction::class)
        ->args([service('tmonier_bulk_actions.resource_event_dispatcher')])
        ->tag($tag)
    ;

    // Orders, payments, shipments
    $services
        ->set('tmonier_bulk_actions.action.order_cancel', CancelOrderAction::class)
        ->args([service('tmonier_bulk_actions.transition_applier')])
        ->tag($tag)
    ;
    $services
        ->set('tmonier_bulk_actions.action.order_payment_complete', CompleteOrderPaymentAction::class)
        ->args([service('tmonier_bulk_actions.transition_applier')])
        ->tag($tag)
    ;
    $services
        ->set('tmonier_bulk_actions.action.payment_complete', CompletePaymentAction::class)
        ->args([service('tmonier_bulk_actions.transition_applier')])
        ->tag($tag)
    ;
    $services
        ->set('tmonier_bulk_actions.action.shipment_ship', ShipShipmentAction::class)
        ->args([service('tmonier_bulk_actions.transition_applier')])
        ->tag($tag)
    ;

    // Customers
    $services
        ->set('tmonier_bulk_actions.action.customer_assign_group', AssignCustomerGroupAction::class)
        ->args([service('tmonier_bulk_actions.resource_event_dispatcher')])
        ->tag($tag)
    ;
    $services
        ->set('tmonier_bulk_actions.action.customer_remove_group', RemoveCustomerGroupAction::class)
        ->args([service('tmonier_bulk_actions.resource_event_dispatcher')])
        ->tag($tag)
    ;
    foreach (['enable' => true, 'disable' => false] as $name => $enable) {
        $services
            ->set(sprintf('tmonier_bulk_actions.action.customer_%s_account', $name), ToggleCustomerAccountAction::class)
            ->args([$enable, service('tmonier_bulk_actions.resource_event_dispatcher')])
            ->tag($tag)
        ;
    }

    // Forms
    foreach ([BulkActionType::class, AddProductsToTaxonType::class, RemoveProductsFromTaxonType::class, ProductChannelsType::class, AssignCustomerGroupType::class] as $formType) {
        $services->set($formType)->tag('form.type');
    }

    // Back office
    $services
        ->set('tmonier_bulk_actions.controller.admin', BulkActionController::class)
        ->args([
            service('tmonier_bulk_actions.registry'),
            service('tmonier_bulk_actions.processor'),
            service('translator'),
            service('form.factory'),
        ])
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public()
    ;

    $services
        ->set('tmonier_bulk_actions.twig.extension', BulkActionsExtension::class)
        ->args([service('tmonier_bulk_actions.registry')])
        ->tag('twig.extension')
    ;
};
