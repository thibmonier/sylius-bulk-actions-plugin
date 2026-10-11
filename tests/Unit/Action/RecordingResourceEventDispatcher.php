<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Action;

use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Resource\ResourceEventDispatcherInterface;

/** Records the resource events of the actions; can simulate a listener stopping the pre event. */
final class RecordingResourceEventDispatcher implements ResourceEventDispatcherInterface
{
    /** @var list<string> */
    public array $dispatched = [];

    public function __construct(private readonly ?string $stopMessage = null)
    {
    }

    public function dispatchPreEvent(string $resource, string $event, object $subject): ?BulkActionResult
    {
        $this->dispatched[] = sprintf('%s.pre_%s', $resource, $event);

        return null === $this->stopMessage ? null : BulkActionResult::skipped($this->stopMessage);
    }

    public function postEventCallback(string $resource, string $event, object $subject): \Closure
    {
        return function () use ($resource, $event): void {
            $this->dispatched[] = sprintf('%s.post_%s', $resource, $event);
        };
    }
}
