# OpenSalesTax for Drupal Commerce

> **v0.1.0-alpha.1.** Installable; passes 56 unit tests; not yet
> validated against a live Drupal Commerce storefront. The live
> validation lands the `v0.1.0` (no-alpha) tag.

A free, self-hostable Drupal Commerce 3.x tax type plugin that swaps
manual tax-rate tables for destination-based US sales tax via the
[OpenSalesTax engine](https://github.com/ejosterberg/opensalestax). No
per-transaction fees, no SaaS lock-in — merchants run both Drupal
Commerce and OpenSalesTax on their own infrastructure.

> **Tax calculations are provided as-is for convenience. The merchant
> is solely responsible for tax-collection accuracy and remittance to
> the appropriate jurisdictions. Verify against your state Department
> of Revenue before remitting.**

## What this module does

- Registers OpenSalesTax as a Drupal Commerce **Tax Type** plugin
  (`@CommerceTaxType(id = "opensalestax")`). Drupal Commerce
  auto-discovers it once the module is enabled.
- When a US/USD order with a 5-digit shipping ZIP reaches the tax
  pipeline, the plugin calls `POST /v1/calculate` on your engine and
  writes one tax adjustment per jurisdiction onto the order (so the
  cart and order screens render "Minnesota State Sales Tax",
  "Hennepin County Tax", etc. — not a single opaque tax line).
- Caches responses per `(zip5, line-signature)` in Drupal's
  `cache.default` bin for 24 hours by default.
- Falls back silently (no tax line, no fatal) on non-US, non-USD,
  missing ZIP, or any engine error.

## What this module does NOT do

- File or remit tax — **calculation only**. The merchant remits.
- Validate addresses.
- Handle non-USD currencies or non-US destinations (passes those
  through, no tax line written).
- Handle tax-exempt customers, customer groups, or per-store-entity
  configuration. (v0.2+.)
- Tax shipping lines. (v0.2+.)
- Ship with the engine bundled — point it at your own
  [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax).

## Compatibility matrix

| Drupal core | Drupal Commerce | PHP    | Status |
| ----------- | --------------- | ------ | ------ |
| 10.3+       | 3.x             | 8.1+   | tested |
| 11.0+       | 3.x             | 8.1+   | should work |

The module hard-pins **calculation-only** behavior — no schema
changes, no service overrides. It coexists with Drupal Commerce's
built-in flat-rate tax types and applies first when its applies()
gate matches.

## Install

```bash
composer require ejosterberg/opensalestax-drupal-commerce
drush en opensalestax_commerce -y
drush cache:rebuild
```

The Composer install transparently pulls in the
[`ejosterberg/opensalestax`](https://packagist.org/packages/ejosterberg/opensalestax)
PHP SDK.

## Configure

Visit **Commerce → Configuration → OpenSalesTax**
(`/admin/commerce/config/opensalestax`).

| Field | Default | Purpose |
| --- | --- | --- |
| **Engine API URL** | (empty) | Base URL of your OpenSalesTax engine, e.g. `https://ost.example.com`. Empty = module inert. |
| **API Key (optional)** | (empty) | `X-API-Key` header value if your engine requires authentication. Stored as a config string; blank-field-on-save preserves the existing key. |
| **Restrict to public IPs (SSRF defense)** | ON | Reject any engine URL whose host resolves to a private, loopback, link-local, CGNAT, or multicast IP. Turn OFF only when the engine is on the same private network as Drupal (e.g. `http://10.x.x.x:8080`). |
| **Cache TTL (seconds)** | 86400 (24h) | How long to cache engine responses per `(zip5, line-signature)`. Minimum 3600. |
| **Engine HTTP timeout (seconds)** | 10 | Maximum wait for the engine before falling back. |
| **Fail hard on engine error** | OFF | When ON, an unreachable engine blocks checkout. When OFF (default), the failure is logged and checkout proceeds with no tax line. |

Then add **OpenSalesTax (Destination-Based US Sales Tax)** as the Tax
Type on each store via **Commerce → Configuration → Taxes**.

## How it works

1. At checkout, Drupal Commerce's tax pipeline iterates over enabled
   tax types and calls `applies($order)` on each.
2. Our plugin's `applies()` short-circuits to `FALSE` on non-US,
   non-USD, missing ZIP, or missing shipping profile.
3. When `applies()` returns `TRUE`, Drupal Commerce calls `apply($order)`.
   We normalize the order into `(country, currency, zip5,
   line_items[])`, look up the cache, and on miss call the engine via
   the [PHP SDK](https://packagist.org/packages/ejosterberg/opensalestax).
4. For each tax line returned, we write a per-jurisdiction
   `Drupal\commerce_order\Adjustment` of type `tax` with the
   jurisdiction's name as label and `opensalestax:<jurisdiction>` as
   source ID.
5. Drupal Commerce's totals pipeline picks the adjustments up and
   renders them.

If anything goes wrong (engine down, timeout, bad payload), and
**Fail hard on engine error** is OFF (default), the failure is logged
via Drupal's `opensalestax` logger channel and no adjustments are
written — checkout proceeds without tax. The merchant then resolves
the engine outage at their own pace without customer-visible breakage.

## Logging

All engine interactions log structured metadata
(`zip5`, `http_status`, error message) via Drupal's `opensalestax`
logger channel. **Customer addresses and full payloads are never
logged.** The API key is read from config in memory only at request
time and never written to logs.

## Development

```bash
composer install
composer test                       # PHPUnit unit suite (56 tests)
composer stan                       # PHPStan level max
composer audit                      # composer audit (HIGH+ blocking)
```

CI runs the same three checks plus a DCO sign-off check on PRs.

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for branch model, DCO sign-off,
and the quality gate.

## Security

See [`SECURITY.md`](SECURITY.md) for responsible-disclosure guidance and
[`docs/SECURITY-REVIEW.md`](docs/SECURITY-REVIEW.md) for the threat
model with mitigation status.

## Related projects

- [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax)
- [OpenSalesTax PHP SDK](https://github.com/ejosterberg/opensalestax-php)
- [opensalestax-magento](https://github.com/ejosterberg/opensalestax-magento)
- [opensalestax-woocommerce](https://github.com/ejosterberg/opensalestax-woocommerce)
- [opensalestax-vendure](https://github.com/ejosterberg/opensalestax-vendure)
- [opensalestax-medusa](https://github.com/ejosterberg/opensalestax-medusa)
- [opensalestax-saleor](https://github.com/ejosterberg/opensalestax-saleor)

## License

Apache-2.0 — see [`LICENSE`](LICENSE). Apache 2.0 is GPLv2-compatible,
so Drupal.org contrib eligibility is preserved for a future listing.

## DCO sign-off

Every commit signed off with `-s`. CI rejects unsigned commits. See
[`CONTRIBUTING.md`](CONTRIBUTING.md).
