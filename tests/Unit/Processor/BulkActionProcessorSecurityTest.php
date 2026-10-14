<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Processor;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Resource\Metadata\MetadataInterface;
use Sylius\Resource\Metadata\RegistryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Processor\BulkActionProcessor;
use Tmonier\SyliusBulkActionsPlugin\Processor\ItemLabelerInterface;

/**
 * Ensures that internal exception messages never leak into the bulk action report
 * (OWASP 2025 A10 — Mishandling of Exceptional Conditions).
 */
final class BulkActionProcessorSecurityTest extends TestCase
{
    private const INTERNAL_MESSAGE = 'SQLSTATE[23000]: Integrity constraint violation — internal detail 42';

    public function testActionExceptionIsNotExposedInTheReport(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $action = $this->createThrowingAction(new \RuntimeException(self::INTERNAL_MESSAGE));

        $report = $this->makeProcessor($this->makeEntityManager(), $logger)->process($action, [1]);

        self::assertSame(1, $report->countFailed());
        $reason = $report->getItems(BulkActionResult::FAILED)[0]->getResult()->getReason();
        self::assertSame('tmonier_bulk_actions.fail.unexpected_error', $reason);
        self::assertStringNotContainsString('internal detail 42', (string) $reason);
        self::assertStringNotContainsString('SQLSTATE', (string) $reason);
    }

    public function testActionExceptionIsLogged(): void
    {
        $exception = new \RuntimeException(self::INTERNAL_MESSAGE);
        $action = $this->createThrowingAction($exception);

        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::atLeastOnce())
            ->method('error')
            ->with(
                self::anything(),
                self::callback(static fn (array $ctx): bool => isset($ctx['exception']) && $ctx['exception'] === $exception),
            );

        $this->makeProcessor($this->makeEntityManager(), $logger)->process($action, [1]);
    }

    public function testFlushExceptionIsNotExposedInTheReport(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $action = $this->createSuccessfulAction();

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->makeEntityManager();
        $em->method('flush')->willThrowException(new \RuntimeException(self::INTERNAL_MESSAGE));

        $report = $this->makeProcessor($em, $logger)->process($action, [1]);

        self::assertSame(1, $report->countFailed());
        $reason = $report->getItems(BulkActionResult::FAILED)[0]->getResult()->getReason();
        self::assertSame('tmonier_bulk_actions.fail.flush_error', $reason);
        self::assertStringNotContainsString('internal detail 42', (string) $reason);
        self::assertStringNotContainsString('SQLSTATE', (string) $reason);
    }

    public function testFlushExceptionIsLogged(): void
    {
        $exception = new \RuntimeException(self::INTERNAL_MESSAGE);
        $action = $this->createSuccessfulAction();

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->makeEntityManager();
        $em->method('flush')->willThrowException($exception);

        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::atLeastOnce())
            ->method('error')
            ->with(
                self::anything(),
                self::callback(static fn (array $ctx): bool => isset($ctx['exception']) && $ctx['exception'] === $exception),
            );

        $this->makeProcessor($em, $logger)->process($action, [1]);
    }

    /** @return EntityManagerInterface&MockObject */
    private function makeEntityManager(): EntityManagerInterface
    {
        $item = new \stdClass();

        /** @var ClassMetadata<\stdClass>&MockObject $classMetadata */
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->method('getIdentifierValues')->willReturn(['id' => 1]);

        /** @var EntityRepository<\stdClass>&MockObject $repository */
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$item]);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->method('getClassMetadata')->willReturn($classMetadata);
        $em->method('isOpen')->willReturn(true);
        $em->method('contains')->willReturn(true);

        return $em;
    }

    private function makeProcessor(EntityManagerInterface $entityManager, LoggerInterface $logger): BulkActionProcessor
    {
        $item = new \stdClass();

        /** @var MetadataInterface&MockObject $metadata */
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getClass')->with('model')->willReturn($item::class);

        /** @var RegistryInterface&MockObject $resourceRegistry */
        $resourceRegistry = $this->createMock(RegistryInterface::class);
        $resourceRegistry->method('get')->willReturn($metadata);

        /** @var ManagerRegistry&MockObject $managerRegistry */
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagerForClass')->willReturn($entityManager);

        /** @var EventDispatcherInterface&MockObject $eventDispatcher */
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        /** @var ItemLabelerInterface&MockObject $itemLabeler */
        $itemLabeler = $this->createMock(ItemLabelerInterface::class);
        $itemLabeler->method('label')->willReturn('#1');

        return new BulkActionProcessor(
            $resourceRegistry,
            $managerRegistry,
            $eventDispatcher,
            $itemLabeler,
            $logger,
            maxItems: 100,
            batchSize: 50,
        );
    }

    private function createThrowingAction(\Throwable $exception): BulkActionInterface
    {
        /** @var BulkActionInterface&MockObject $action */
        $action = $this->createMock(BulkActionInterface::class);
        $action->method('getCode')->willReturn('test_action');
        $action->method('getResource')->willReturn('test_resource');
        $action->method('supports')->willReturn(true);
        $action->method('execute')->willThrowException($exception);

        return $action;
    }

    private function createSuccessfulAction(): BulkActionInterface
    {
        /** @var BulkActionInterface&MockObject $action */
        $action = $this->createMock(BulkActionInterface::class);
        $action->method('getCode')->willReturn('test_action');
        $action->method('getResource')->willReturn('test_resource');
        $action->method('supports')->willReturn(true);
        $action->method('execute')->willReturn(BulkActionResult::applied());

        return $action;
    }
}
