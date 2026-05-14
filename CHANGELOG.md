# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-alpha.1] — 2026-05-13

### Added

- Drupal Commerce 3.x tax type plugin (`@CommerceTaxType(id =
  "opensalestax")`) — auto-discovered via Drupal's plugin manager
  when the module is enabled.
- HTTP boundary via the [`ejosterberg/opensalestax` PHP
  SDK](https://packagist.org/packages/ejosterberg/opensalestax) — the
  connector never speaks to the engine directly.
- Admin settings form at `/admin/commerce/config/opensalestax` —
  Engine URL, API key, SSRF restriction toggle, cache TTL, HTTP
  timeout, fail-hard toggle.
- SSRF-defense URL validator rejecting RFC-1918, loopback, link-local
  (cloud metadata), CGNAT, and multicast IPs when "Restrict to
  public IPs" is on (default ON).
- ZIP-keyed cache via Drupal's `cache.default` bin with 24h default
  TTL (configurable 1h–7d).
- Country/currency/ZIP gates — non-US, non-USD, or missing ZIP orders
  bypass the engine cleanly (no fatal, no tax line).
- Fail-soft policy by default — engine errors logged via the
  `opensalestax` logger channel and checkout proceeds with no tax
  line. Opt-in fail-hard for merchants who prefer to block checkout
  rather than mis-collect.
- Per-jurisdiction adjustment lines so the cart and order screens
  render "Minnesota State Sales Tax", "Hennepin County Tax", etc.,
  instead of one opaque tax row.
- 56 PHPUnit tests covering: SSRF validator (all reserved ranges),
  client factory, calculator (gates, cache, fail-soft, fail-hard),
  tax type plugin (annotation discovery, applies(), apply(),
  adjustment writing), settings form (validation, key preservation).
- PHPStan level max — 0 errors.
- SonarQube quality gate — 0 BUGS / 0 VULNERABILITIES / 0 CODE SMELLS
  / 0 SECURITY HOTSPOTS.
- `docs/SECURITY-REVIEW.md` — threat model with 12 threats and
  mitigation status.
- CI workflow running PHPUnit + PHPStan + composer audit + DCO check
  on every push and PR.

### Known limitations (v0.1)

- No live-instance integration test yet — the alpha tag will lift
  once that lands on the test VM.
- No customer-group / tax-exempt customer support (v0.2).
- No shipping-line tax handling (v0.2).
- No per-store-entity configuration (v0.2).
- No refund/return adjustment yet (v0.2).
- Drupal 9 not supported (Drupal 9 is EOL).

[Unreleased]: https://github.com/ejosterberg/opensalestax-drupal-commerce/compare/v0.1.0-alpha.1...HEAD
[0.1.0-alpha.1]: https://github.com/ejosterberg/opensalestax-drupal-commerce/releases/tag/v0.1.0-alpha.1
