<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusBulkActionsPlugin\Unit\Shipment;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusBulkActionsPlugin\Shipment\TrackingCodeParser;

final class TrackingCodeParserTest extends TestCase
{
    public function testItParsesTheUsualSeparatorsAndIgnoresInvalidLines(): void
    {
        $text = "000000042;1Z999AA10123456784\r\n"
            . "#000000043, 1Z999AA10123456785\n"
            . "\n"
            . "000000044\tCOLISSIMO-6A123\n"
            . "\"000000045\";\"QUOTED\"\n"
            . "no separator\n"
            . ";missing order\n"
            . "000000046;\n";

        self::assertSame([
            ['order_number' => '000000042', 'tracking_code' => '1Z999AA10123456784'],
            ['order_number' => '000000043', 'tracking_code' => '1Z999AA10123456785'],
            ['order_number' => '000000044', 'tracking_code' => 'COLISSIMO-6A123'],
            ['order_number' => '000000045', 'tracking_code' => 'QUOTED'],
        ], (new TrackingCodeParser())->parse($text));
    }

    public function testItReturnsNothingForAnEmptyText(): void
    {
        self::assertSame([], (new TrackingCodeParser())->parse(''));
    }
}
