# Integration check — v0.1.0-alpha.1

> Live-engine connectivity verification. Run before declaring the
> module alpha-ready.

## Engine reachability (live)

```bash
$ curl -s http://10.32.161.126:8080/v1/health
{"status":"ok","version":"0.55.4","database_connected":true}
```

Confirmed 2026-05-13. The engine answers `200 OK` from Eric's LAN at
the test moment.

## SDK round-trip (local PHP)

Drop the following into `tools/smoke-test.php` and run with PHP CLI
(not committed — script lives in scratch / kickoff-archive on the
orchestrator's machine):

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

$client = new \OpenSalesTax\Client(baseUrl: 'http://10.32.161.126:8080');
$response = $client->calculate(
    new \OpenSalesTax\Address(zip5: '55401'),
    [new \OpenSalesTax\LineItem(amount: '100.00')]
);
printf("subtotal=%s, tax_total=%s, lines=%d\n",
    $response->subtotal, $response->taxTotal, count($response->lines));
foreach ($response->lines as $line) {
    foreach ($line->jurisdictions as $j) {
        printf("  - %s: %s (%s%%)\n", $j->name ?? '?', $j->tax ?? '?', $j->ratePct ?? '?');
    }
}
```

**Actual output (run 2026-05-13, engine v0.55.4, $100.00 to ZIP
55401):**

```
engine: http://10.32.161.126:8080
health: status=ok, version=0.55.4, db_connected=true
calculate: subtotal=100.00 tax_total=9.0250 lines=1
  - Minneapolis: 0.5000 (0.50000%)
  - Hennepin County: 0.1500 (0.15000%)
  - Minnesota: 6.8750 (6.87500%)
  - Hennepin County Transit Sales Tax: 0.5000 (0.50000%)
  - Metro Area Transportation Sales Tax: 0.7500 (0.75000%)
  - Metro Area Sales and Use Tax for Housing: 0.2500 (0.25000%)
```

Six jurisdictions reconcile to `tax_total=9.0250`. Health + calculate
round-trip both green. Ready for the on-VM functional test.

## What the main orchestrator agent will do next

The main agent operates the test VM `drupal-commerce-test` (VMID
917). The live integration test sequence:

1. Drush a fresh Drupal 10.3 + Commerce 3.x install on the VM.
2. `composer require ejosterberg/opensalestax-drupal-commerce:dev-main`
   pointed at the public GitHub repo.
3. `drush en opensalestax_commerce -y`.
4. Browse to `/admin/commerce/config/opensalestax`, paste
   `http://10.32.161.126:8080`, disable "Restrict to public IPs"
   (engine is LAN-only), save.
5. Add OpenSalesTax as a Tax Type on the test store.
6. Add a product, drive a US cart through to the checkout-with-
   address step (use ZIP `55401`).
7. Expect the order to show a non-zero tax line broken down by
   jurisdiction.
8. Repeat with ZIP `90210` to confirm caching is per-ZIP.
9. Repeat with a Canadian address → expect no tax line.
10. Stop the engine (or block the route on the VM) and try
    again → expect no tax line, no fatal (fail-soft).
11. Re-enable, flip "Fail hard on engine error" ON, stop the engine,
    try again → expect a user-visible error blocking checkout.

If all 11 steps pass, the alpha tag is replaced with the
no-suffix `v0.1.0` stable tag and the README's alpha banner is
dropped.

## Smoke-test recording

When the live integration test runs, the result is recorded at
`docs/integration-runs/YYYY-MM-DD-<vm-snapshot>.md` — append-only
log of every live run, success or fail, so we have a paper trail.
