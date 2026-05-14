<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/**
 * Smoke test against the live OpenSalesTax engine.
 *
 * Runs from the project root:
 *   php tools/smoke-test.php
 *
 * Defaults to the LAN engine at 10.32.161.126:8080. Override with
 *   OPENSALESTAX_URL=https://your.engine php tools/smoke-test.php
 *
 * Not part of CI — CI has no network access to the engine VM.
 */

require __DIR__ . '/../vendor/autoload.php';

$url = getenv('OPENSALESTAX_URL') ?: 'http://10.32.161.126:8080';
echo "engine: {$url}\n";

$client = new OpenSalesTax\Client(baseUrl: $url);
$health = $client->health();
echo "health: status={$health->status}, version={$health->version}, db_connected="
    . ($health->databaseConnected ? 'true' : 'false') . "\n";

$response = $client->calculate(
    new OpenSalesTax\Address(zip5: '55401'),
    [new OpenSalesTax\LineItem(amount: '100.00')]
);
printf("calculate: subtotal=%s tax_total=%s lines=%d\n",
    $response->subtotal,
    $response->taxTotal,
    count($response->lines)
);
foreach ($response->lines as $line) {
    foreach ($line->jurisdictions as $j) {
        $name = property_exists($j, 'name') && is_string($j->name) ? $j->name : '?';
        $tax = property_exists($j, 'tax') && is_string($j->tax) ? $j->tax : '?';
        $ratePct = property_exists($j, 'ratePct') && is_string($j->ratePct) ? $j->ratePct : '?';
        printf("  - %s: %s (%s%%)\n", $name, $tax, $ratePct);
    }
}
