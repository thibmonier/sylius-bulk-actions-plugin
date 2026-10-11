<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Resource;

use Sylius\Resource\Symfony\EventDispatcher\GenericEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;

final class ResourceEventDispatcher implements ResourceEventDispatcherInterface
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function dispatchPreEvent(string $resource, string $event, object $subject): ?BulkActionResult
    {
        $preEvent = new GenericEvent($subject);
        $this->eventDispatcher->dispatch($preEvent, sprintf('%s.pre_%s', $resource, $event));

        if (!$preEvent->isStopped()) {
            return null;
        }

        $message = $preEvent->getMessage();
        if ('' === $message) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.stopped_by_listener');
        }

        /** @var array<string, string|int|float> $parameters */
        $parameters = array_filter($preEvent->getMessageParameters(), 'is_scalar');

        return BulkActionResult::skipped($message, $parameters);
    }

    public function postEventCallback(string $resource, string $event, object $subject): \Closure
    {
        return function () use ($resource, $event, $subject): void {
            $this->eventDispatcher->dispatch(new GenericEvent($subject), sprintf('%s.post_%s', $resource, $event));
        };
    }
}
