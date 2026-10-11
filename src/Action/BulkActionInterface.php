<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Action;

use Symfony\Component\Form\FormTypeInterface;

/**
 * A bulk action of the admin grids. Services implementing this interface are registered
 * automatically (autoconfiguration) or with the "tmonier_bulk_actions.action" tag.
 *
 * Every selected item is given to execute() on its own: an action must never flush the entity
 * manager (the processor flushes in batches) and returns a skipped result instead of throwing
 * when the item cannot be changed (wrong state, already done...).
 */
interface BulkActionInterface
{
    /** Unique code, used in URLs and in the "actions" configuration. */
    public function getCode(): string;

    /** Sylius resource alias of the items, e.g. "sylius.product": the action is offered on the grids of this resource. */
    public function getResource(): string;

    /** Translation key (messages domain) of the action label. */
    public function getLabel(): string;

    /** Tabler icon of the action, e.g. "tabler:eye". */
    public function getIcon(): string;

    /**
     * Form type asking the data given to execute(), null when the action needs no input.
     *
     * @return class-string<FormTypeInterface>|null
     */
    public function getFormType(): ?string;

    public function supports(object $item): bool;

    /** @param array<string, mixed> $data the submitted data of the form type (empty without form) */
    public function execute(object $item, array $data): BulkActionResult;
}
