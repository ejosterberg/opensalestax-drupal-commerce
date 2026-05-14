# Spec — opensalestax-drupal-commerce v0.1

> **Status:** Locked for v0.1.0-alpha.1.
> **Promoted from** the hub's `targets/drupal-commerce/spec.md` (2026-05-13).
> **Public repo:** `ejosterberg/opensalestax-drupal-commerce`

## Goal

Drupal Commerce module that registers OpenSalesTax as a tax type plugin,
giving Drupal Commerce merchants destination-based US sales tax without
paying Avalara or TaxJar fees. They install the module via Composer,
enter their self-hosted OpenSalesTax engine URL, and tax now calculates
on every checkout to a US ZIP.

## In scope (v0.1)

- Drupal module installable via Composer
  (`composer require ejosterberg/opensalestax-drupal-commerce`)
- A `TaxType` plugin (annotated PHP class) discovered automatically by
  Drupal Commerce's plugin manager
- ConfigFormBase-based admin form at
  `/admin/commerce/config/opensalestax`
- Simple-config storage of: engine base URL, API key (optional),
  restrict-to-public-IPs flag, cache TTL, fail-hard flag
- ZIP-keyed cache via Drupal's cache API, 24h default TTL
- SSRF-defense URL validator (RFC-1918, loopback, CGNAT, link-local,
  multicast, reserved ranges)
- US-only / USD-only gates (any other country/currency falls through)
- Fail-soft by default (engine error → log warning, return zero-tax)
- README + CHANGELOG + LICENSE + CONTRIBUTING + SECURITY + threat-model
  SECURITY-REVIEW.md
- PHPUnit unit + kernel tests (30+)
- CI workflow: PHPStan level max + phpcs Drupal + PHPUnit + composer
  audit on push/PR

## Out of scope

- Tax filing / remittance (constitution §3)
- Non-USD currencies (engine constraint)
- Drupal 9 (EOL)
- Per-store-entity tax-type configuration
- Tax-exempt customers / customer groups
- Shipping-line taxation
- Refund/return tax adjustments
- Functional (browser) tests
- Drupal.org listing (separate phase)

## User story

> As a Drupal Commerce admin, I run `composer require
> ejosterberg/opensalestax-drupal-commerce`, enable the module via Drush
> (`drush en opensalestax_commerce`), go to **Commerce → Configuration
> → OpenSalesTax**, paste my OpenSalesTax engine URL, save, then add
> "OpenSalesTax" as a Tax Type on my store. Tax now calculates
> correctly for any US shipping address at checkout.

## Open questions — resolved

1. **Drupal Commerce version targeted?**
   *Answer: 3.x (`drupal/commerce:^3.0`). 2.x is in long-term support
   but new development goes to 3.x.*
2. **Replace Drupal Commerce's rate-storage layer or just plug a new
   TaxType?**
   *Answer: just a TaxType plugin. Drupal Commerce's `TaxTypeInterface`
   is the proper extension point; touching rate storage would require
   schema changes we don't need.*
3. **Plugin annotation — global or store-scoped?**
   *Answer: global at v0.1. The annotation is `@CommerceTaxType(id =
   "opensalestax", label = @Translation("OpenSalesTax (Destination-Based
   US Sales Tax)"))`. Store-scoping is a v0.2 enhancement.*
4. **Engine URL — config entity or simple config?**
   *Answer: simple config (`opensalestax_commerce.settings`). Single
   tenant; doesn't need entity overhead.*
5. **SSRF defense — adopt `drupal/restrict_external_url`?**
   *Answer: no. The module owns its own URL validator (mirrors the
   Magento connector's `ApiUrlValidator`). Less external dependency
   surface; tighter test coverage.*
6. **Test framework — KernelTestBase or BrowserTestBase?**
   *Answer: `UnitTestCase` for everything that doesn't need the Drupal
   container; one `KernelTestBase` for the plugin-discovery smoke test.
   `BrowserTestBase` (functional) is deferred to the live-VM phase.*
7. **License compatibility for future Drupal.org listing?**
   *Answer: Apache 2.0 is GPLv2-compatible (FSF compatibility matrix).
   Drupal.org accepts dependencies under any GPL-compatible license; the
   module itself can stay Apache 2.0.*

## Success criteria

A clean Drupal 10.3 + Drupal Commerce 3.x install where:

- `composer require ejosterberg/opensalestax-drupal-commerce` resolves
- `drush en opensalestax_commerce -y` succeeds
- `/admin/commerce/config/opensalestax` renders the settings form
- Adding "OpenSalesTax" as a Tax Type on a store succeeds
- A US-destination cart with a $100 line item gets a correct tax line
  computed from the engine
- An identical cart with engine URL blank → no tax line, no fatal
- An identical cart shipping to Canada or paying in EUR → no tax line,
  no fatal

## Integration shape

| Aspect | Choice |
|---|---|
| Plugin interface | `Drupal\commerce_tax\Plugin\Commerce\TaxType\TaxTypeInterface` (via `RemoteTaxTypeBase`) |
| Plugin annotation | `@CommerceTaxType(id = "opensalestax", ...)` |
| Plugin file | `src/Plugin/Commerce/TaxType/OpenSalesTax.php` |
| HTTP boundary | PHP SDK `ejosterberg/opensalestax:^0.1` |
| Cache | `cache.default` bin, 24h TTL per ZIP |
| Logger | `logger.channel.opensalestax` (auto-registered via `*.services.yml`) |
| Settings storage | Simple config `opensalestax_commerce.settings` |

## SDK dependency

- `ejosterberg/opensalestax:^0.1` (Packagist, verified live)
- PHP `^8.1`

## License & conventions

- Apache 2.0
- DCO sign-off (`-s`) on every commit
- SPDX header `// SPDX-License-Identifier: Apache-2.0` on every PHP file
- Drupal coding standards (`drupal/coder` + `phpcs --standard=Drupal`)
- PHPStan level max
