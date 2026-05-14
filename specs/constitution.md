# Constitution — opensalestax-drupal-commerce

Connector-scoped supplement to the program-wide constitution at
`ejosterberg/open-sales-tax-integrations`. Read the program constitution
first; this file only records the deviations and decisions specific to
this connector.

## §1. License

**Apache 2.0.** Apache 2.0 is GPLv2-compatible per the FSF compatibility
matrix, so the module remains eligible for future Drupal.org submission
(Drupal contrib code lives under GPLv2-or-later, but Drupal.org accepts
dependencies licensed under any GPLv2-compatible license). The module's
own `LICENSE` file is Apache 2.0.

Every PHP source file carries `// SPDX-License-Identifier: Apache-2.0`.

## §2. DCO

Every commit signed off with `-s`. CI enforces.

No `Co-Authored-By: Claude …` trailers. Ever.

## §3. Calculation only

This module never files, never remits, never validates addresses. The
merchant remits. Settings page surfaces the boilerplate disclaimer per
the program constitution §10.

## §4. Dependency arrow

```
opensalestax-drupal-commerce  →  ejosterberg/opensalestax (PHP SDK)  →  /v1/calculate HTTP
```

The module never calls the engine HTTP API directly. All engine I/O goes
through the SDK's `OpenSalesTax\Client::calculate(Address, LineItem[])`.

## §5. Target platforms

- **Drupal core:** 10 (^10.3) and 11 (^11.0). Drupal 9 is EOL — not
  supported.
- **Drupal Commerce:** 3.x.
- **PHP:** 8.1+ (matches Drupal 10's minimum).
- **Database:** anything Drupal supports — module uses Drupal's cache
  API, not raw SQL.

## §6. Module identity

| Field | Value |
|---|---|
| Module machine name | `opensalestax_commerce` |
| Composer package | `ejosterberg/opensalestax-drupal-commerce` |
| Plugin ID | `opensalestax` |
| Plugin scope | Global tax type (applies to all stores; per-store
scoping is v0.2) |
| Configuration entity | `opensalestax_commerce.settings` (simple config) |

## §7. Security posture

Mirrors the Magento connector's defense-in-depth:

- API key supplied via configuration, masked in the admin form
- SSRF-defense URL validator with RFC-1918, loopback, CGNAT, link-local,
  multicast, and reserved-range rejection — opt-in opt-out via a
  `restrict_to_public_ips` config flag (default ON for new sites; merchant
  can opt-out for self-hosted-on-LAN deployments)
- TLS verification is the SDK default (Guzzle peer-verify on); module
  never exposes a "disable TLS" knob
- Fail-soft by default (`fail_hard = false`) — engine outage logs and
  returns zero-tax rather than blocking checkout
- All structured logging via Drupal's logger channel `opensalestax`;
  customer addresses never logged; API key never logged

## §8. Out of v0.1 scope (deferred to v0.2+)

- Per-store-entity scoping
- Tax-exempt customer / customer-group exemption flows
- Shipping-line taxation
- Refund / return tax adjustment
- Drupal.org listing
- Functional tests (we ship unit + kernel tests at v0.1)

## §9. Quality gate (the v0.1.0-alpha.1 gate)

- PHPStan level max / 8 — 0 errors
- PHPUnit — 30+ tests, all green
- SonarQube — 0 BUGS / 0 VULNERABILITIES / 0 CODE SMELLS / 0 SECURITY
  HOTSPOTS
- `composer audit` — 0 advisories HIGH or above
- DCO sign-off on every commit

## §10. Promotion to v0.1.0 (drop the alpha tag)

Same as program constitution §7: end-to-end integration test on a clean
Drupal 10 + Commerce 3 install passes. Until then, the public README
header carries the alpha banner.
