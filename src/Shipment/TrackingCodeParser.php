<?php

declare(strict_types=1);

namespace Tmonier\SyliusBulkActionsPlugin\Shipment;

/**
 * Parses pasted "order number;tracking code" lines (separator ";", "," or tab, as exported by
 * most carriers' spreadsheets). Empty lines and lines without both values are ignored.
 */
final class TrackingCodeParser
{
    /** @return list<array{order_number: string, tracking_code: string}> */
    public function parse(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $parts = preg_split('/[;,\t]/', trim($line), 2);
            if (false === $parts || 2 !== count($parts)) {
                continue;
            }

            $orderNumber = ltrim(trim($parts[0], " \"'"), '#');
            $trackingCode = trim($parts[1], " \"'");
            if ('' === $orderNumber || '' === $trackingCode) {
                continue;
            }

            $lines[] = ['order_number' => $orderNumber, 'tracking_code' => $trackingCode];
        }

        return $lines;
    }
}
