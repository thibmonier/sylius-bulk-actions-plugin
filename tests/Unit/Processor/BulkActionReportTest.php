<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Processor;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Product;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;
use Tmonier\SyliusBulkActionsPlugin\Processor\ItemLabeler;
use Tmonier\SyliusBulkActionsPlugin\Processor\TooManyItemsException;
use Tmonier\SyliusBulkActionsPlugin\Report\BulkActionReport;
use Tmonier\SyliusBulkActionsPlugin\Report\BulkActionReportItem;

final class BulkActionReportTest extends TestCase
{
    public function testItCountsTheOutcomes(): void
    {
        $report = new BulkActionReport('order_cancel');
        $report->add(new BulkActionReportItem(1, '#1', BulkActionResult::applied()));
        $report->add(new BulkActionReportItem(2, '#2', BulkActionResult::skipped('tmonier_bulk_actions.skip.transition_not_allowed')));
        $report->add(new BulkActionReportItem(3, '#3', BulkActionResult::failed('Boom', ['%id%' => 3])));
        $report->add(new BulkActionReportItem(4, '#4', BulkActionResult::applied()));

        self::assertSame('order_cancel', $report->getActionCode());
        self::assertSame(4, $report->count());
        self::assertSame(2, $report->countApplied());
        self::assertSame(1, $report->countSkipped());
        self::assertSame(1, $report->countFailed());
        self::assertSame('#3', $report->getItems(BulkActionResult::FAILED)[0]->getLabel());
        self::assertSame(['%id%' => 3], $report->getItems(BulkActionResult::FAILED)[0]->getResult()->getParameters());
        self::assertTrue($report->getItems(BulkActionResult::FAILED)[0]->getResult()->isFailed());
    }

    public function testItLabelsTheItems(): void
    {
        $labeler = new ItemLabeler();
        $order = new Order();
        $order->setNumber('000042');
        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Cap');
        $product->setCode('CAP');
        $customer = new Customer();
        $customer->setEmail('jane@example.com');

        self::assertSame('#000042', $labeler->label($order, 1));
        self::assertSame('Cap (CAP)', $labeler->label($product, 2));
        self::assertSame('jane@example.com', $labeler->label($customer, 3));
        self::assertSame('#4', $labeler->label(new \stdClass(), 4));
    }

    public function testTheTooManyItemsExceptionKeepsTheNumbers(): void
    {
        $exception = new TooManyItemsException(501, 500);

        self::assertSame(501, $exception->count);
        self::assertSame(500, $exception->maxItems);
    }
}
