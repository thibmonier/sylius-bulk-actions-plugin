<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Processor;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use Sylius\Resource\Metadata\RegistryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Event\BulkActionExecutedEvent;
use Tmonier\SyliusBulkActionsPlugin\Report\BulkActionReport;
use Tmonier\SyliusBulkActionsPlugin\Report\BulkActionReportItem;

/**
 * Runs a bulk action on every selected item, independently: an item that throws is reported as
 * failed and the others go on. Changes are flushed every "batch_size" items; the after-flush
 * callbacks (resource "post" events: e-mails...) run once their batch is flushed. A database
 * error during a flush fails its batch and stops the run, because the entity manager can no
 * longer be trusted.
 */
final class BulkActionProcessor implements BulkActionProcessorInterface
{
    public function __construct(
        private readonly RegistryInterface $resourceRegistry,
        private readonly ManagerRegistry $managerRegistry,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ItemLabelerInterface $itemLabeler,
        private readonly LoggerInterface $logger,
        private readonly int $maxItems,
        private readonly int $batchSize,
    ) {
    }

    public function process(BulkActionInterface $action, array $ids, array $data = []): BulkActionReport
    {
        $ids = array_values(array_unique($ids));
        if (count($ids) > $this->maxItems) {
            throw new TooManyItemsException(count($ids), $this->maxItems);
        }

        [$class, $manager] = $this->classAndManager($action);

        $report = new BulkActionReport($action->getCode());
        $aborted = false;
        foreach (array_chunk($ids, max(1, $this->batchSize)) as $chunk) {
            if ($aborted) {
                foreach ($chunk as $id) {
                    $report->add(new BulkActionReportItem($id, '#' . $id, BulkActionResult::failed('tmonier_bulk_actions.fail.aborted')));
                }

                continue;
            }

            $items = [];
            foreach ($manager->getRepository($class)->findBy(['id' => $chunk]) as $item) {
                $items[(string) $this->idOf($manager, $item)] = $item;
            }

            /** @var list<BulkActionReportItem> $pending */
            $pending = [];
            foreach ($chunk as $id) {
                $item = $items[(string) $id] ?? null;
                if (null === $item) {
                    $report->add(new BulkActionReportItem($id, '#' . $id, BulkActionResult::skipped('tmonier_bulk_actions.skip.not_found')));

                    continue;
                }

                $label = $this->itemLabeler->label($item, $id);
                $result = $this->executeOne($action, $item, $data, $manager);
                $reportItem = new BulkActionReportItem($id, $label, $result);
                if ($result->isApplied()) {
                    $pending[] = $reportItem;
                } else {
                    $report->add($reportItem);
                }
            }

            if ([] === $pending) {
                continue;
            }

            try {
                $manager->flush();
            } catch (\Throwable $exception) {
                $this->logger->error('Bulk action "{action}": flush failed, the run is stopped.', ['action' => $action->getCode(), 'exception' => $exception]);
                foreach ($pending as $reportItem) {
                    $report->add(new BulkActionReportItem($reportItem->getId(), $reportItem->getLabel(), BulkActionResult::failed($exception->getMessage())));
                }
                $aborted = true;

                continue;
            }

            foreach ($pending as $reportItem) {
                $report->add($reportItem);
                $this->runAfterFlush($action, $reportItem);
            }
        }

        $this->eventDispatcher->dispatch(new BulkActionExecutedEvent($action, $report, $data), BulkActionExecutedEvent::NAME);

        return $report;
    }

    public function preview(BulkActionInterface $action, array $ids, int $limit): array
    {
        [$class, $manager] = $this->classAndManager($action);
        $ids = array_slice(array_values(array_unique($ids)), 0, $limit);

        $labels = [];
        foreach ($manager->getRepository($class)->findBy(['id' => $ids], ['id' => 'ASC']) as $item) {
            $labels[] = $this->itemLabeler->label($item, $this->idOf($manager, $item));
        }

        return $labels;
    }

    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    /** @return array{class-string, ObjectManager} */
    private function classAndManager(BulkActionInterface $action): array
    {
        /** @var class-string $class */
        $class = $this->resourceRegistry->get($action->getResource())->getClass('model');
        $manager = $this->managerRegistry->getManagerForClass($class);
        if (null === $manager) {
            throw new \LogicException(sprintf('No Doctrine manager for "%s".', $class));
        }

        return [$class, $manager];
    }

    /** @param array<string, mixed> $data */
    private function executeOne(BulkActionInterface $action, object $item, array $data, ObjectManager $manager): BulkActionResult
    {
        if (!$action->supports($item)) {
            return BulkActionResult::skipped('tmonier_bulk_actions.skip.not_supported');
        }

        try {
            return $action->execute($item, $data);
        } catch (\Throwable $exception) {
            $this->logger->error('Bulk action "{action}" failed on an item.', ['action' => $action->getCode(), 'exception' => $exception]);
            // Do not let a half-applied change be flushed with the batch.
            if ($manager instanceof EntityManagerInterface && $manager->isOpen() && $manager->contains($item)) {
                try {
                    $manager->refresh($item);
                } catch (\Throwable) {
                }
            }

            return BulkActionResult::failed($exception->getMessage());
        }
    }

    private function runAfterFlush(BulkActionInterface $action, BulkActionReportItem $reportItem): void
    {
        $callback = $reportItem->getResult()->getAfterFlush();
        if (null === $callback) {
            return;
        }

        try {
            $callback();
        } catch (\Throwable $exception) {
            // The change is saved: the item stays "applied", the error is logged.
            $this->logger->error('Bulk action "{action}": an after-flush listener failed for {item}.', [
                'action' => $action->getCode(),
                'item' => $reportItem->getLabel(),
                'exception' => $exception,
            ]);
        }
    }

    private function idOf(ObjectManager $manager, object $item): int|string
    {
        $identifier = $manager->getClassMetadata($item::class)->getIdentifierValues($item);
        $id = reset($identifier);

        return is_int($id) || is_string($id) ? $id : '';
    }
}
