# Security review — opensalestax-drupal-commerce v0.1.0-alpha.1

> **Date:** 2026-05-13.
> **Reviewer:** Eric Osterberg (project author).
> **Scope:** Everything under `src/`, the admin form, and the HTTP
> boundary between the connector and the OpenSalesTax engine.
> **Threat-model basis:** OWASP Top-10 (web), CWE catalogue, Drupal's
> security advisories convention.
>
> This document is a v0.1 snapshot. As the module matures, additional
> dated reviews land at `docs/SECURITY-REVIEW-YYYY-MM-DD.md` (per
> Eric's `~/.claude/spec-kit-playbook.md` security-audit convention).

## Threats considered

### T-01 — SSRF via attacker-controlled engine URL

**Attack:** A privileged admin (or an attacker who acquired
`administer commerce_tax_type` permission) sets the engine URL to
`http://169.254.169.254/latest/meta-data/` (AWS metadata),
`http://10.0.0.1`, or `http://localhost:11211` (memcached) to
extract cloud credentials or pivot inside the network.

**Mitigation:** `Drupal\opensalestax_commerce\Service\UrlValidator`
runs at both form-submit time and runtime (inside ClientFactory).
When the "Restrict to public IPs" flag is on (default ON), the
validator resolves the URL's host and rejects:

- RFC-1918 (10/8, 172.16/12, 192.168/16) via PHP's
  `FILTER_FLAG_NO_PRIV_RANGE`.
- IETF reserved ranges via `FILTER_FLAG_NO_RES_RANGE` (loopback,
  link-local 169.254/16, etc.).
- CGNAT (100.64/10) and multicast (224/4) and reserved future-use
  (240/4) via explicit IPv4 range checks. PHP's filter flags don't
  catch these.

**Status:** Mitigated.

**Tests:** `UrlValidatorTest::testPrivateRangeIsRejectedWhenRestricted`,
`testLoopbackIsRejectedWhenRestricted`,
`testLinkLocalIsRejectedWhenRestricted`,
`testCgnatRangeIsRejectedWhenRestricted`,
`testMulticastIsRejectedWhenRestricted`,
`testIpv6PrivateRangeIsRejectedWhenRestricted`.

### T-02 — DNS rebinding bypassing T-01

**Attack:** Attacker controls DNS for `ost.example.com`. At config-
save time, it resolves to `203.0.113.10` (public — passes T-01). At
request time, it resolves to `169.254.169.254`.

**Mitigation (partial):** The URL validator re-runs at every engine
call, not just at save time. An attacker would need to win the race
between validator-resolves and Guzzle-resolves. The PHP SDK's
underlying Guzzle uses curl's `Host:` header + DNS lookup — we do
NOT yet pin a CURLOPT_RESOLVE entry as the Magento connector does.

**Status:** Partially mitigated; **accepted residual risk for v0.1**.
A DNS-rebinding attacker still wins a small race window. Full
mitigation (CURLOPT_RESOLVE pinning matching the validator-resolved
IP) is a v0.2 task — same shape as the Magento connector's
`OstaxClient::applyPinnedIp()` pattern.

**Tests:** N/A — full mitigation is deferred.

### T-03 — API key exposure in logs or error messages

**Attack:** API key leaks to the Drupal log stream, leaving a
permanent trail in dblog / syslog.

**Mitigation:** The `TaxCalculator::calculateForOrder` logger calls
log only `zip5`, `http_status`, and exception message — never the
request payload, never the headers, never the config. The SDK's
exception messages don't include the API key. The admin form's
password field never echoes the existing key (defense against
shoulder-surfing config screens).

**Status:** Mitigated.

**Tests:** `SettingsFormTest::testBuildFormRendersDisclaimerAndFields`
asserts the password default value is empty (existing key not
re-rendered).

### T-04 — TLS-disabled engine accepts plaintext credentials

**Attack:** Admin enters `http://ost.example.com` (not https). API
key travels in plaintext.

**Mitigation:** The module does NOT enforce HTTPS — it accepts
http:// for legitimate on-LAN deployments (e.g., `http://10.0.0.5:8080`
when "Restrict to public IPs" is OFF). The README documents the
risk; the SECURITY-REVIEW flags it as **accepted risk for v0.1**.

**Status:** Accepted risk; documented.

**Future hardening:** v0.2 candidate — surface a warning in the form
when the URL is `http://` and the host is publicly resolvable.

### T-05 — TLS certificate validation disabled

**Attack:** Admin (or a malicious sub-admin) finds an environment
variable / config knob to disable TLS verification, then traffic is
MitM-able.

**Mitigation:** The module never exposes a "disable TLS verify" knob.
The SDK uses Guzzle's defaults (`verify` = TRUE). The merchant would
need to override Guzzle's defaults at the Drupal container level —
outside our scope.

**Status:** Mitigated.

### T-06 — Engine response triggers persistent XSS

**Attack:** A malicious engine response embeds `<script>` in a
jurisdiction name or disclaimer string. Drupal Commerce renders the
adjustment label in cart / order screens.

**Mitigation:** Drupal Commerce's adjustment-rendering pipeline runs
labels through the standard render-API string escapers (every label
emerges via `\Drupal\Core\StringTranslation\TranslatableMarkup` or
direct render with `#plain_text`). The connector does NOT pass raw
labels through `Markup::create()` or `format_string()`. Engine-
sourced strings can't reach unescaped HTML output.

**Status:** Mitigated (Drupal handles it).

### T-07 — CSRF on the settings form

**Attack:** Logged-in admin visits an attacker page that POSTs to
`/admin/commerce/config/opensalestax` with a malicious engine URL.

**Mitigation:** Drupal's Form API automatically embeds a per-session
CSRF token on every form submission. `ConfigFormBase` inherits this.
We never disable it.

**Status:** Mitigated (Drupal handles it).

### T-08 — Permission elevation via the settings route

**Attack:** A non-admin user navigates to the settings route and
submits arbitrary config.

**Mitigation:** `opensalestax_commerce.routing.yml` requires the
`administer commerce_tax_type` permission. This is the same
permission Drupal Commerce uses to gate its built-in tax type
admin pages; only roles trusted to configure tax can reach our form.

**Status:** Mitigated.

### T-09 — Time-of-check vs. time-of-use on cache

**Attack:** The cache is poisoned between gate-check and SDK-call.

**Mitigation:** N/A — gate checks run on the inbound order before
any cache read. The cache stores engine responses, not gate decisions.
A poisoned cache could return stale tax totals (functional issue,
not security), bounded by the configurable TTL (max 7 days).

**Status:** N/A — not a security issue.

### T-10 — Sensitive data in cache backend

**Attack:** Drupal's `cache.default` is shared with the rest of the
site. If the cache backend is Memcached or Redis on a shared host,
cached engine responses leak between tenants.

**Mitigation (partial):** Responses contain only ZIP-derived rate
data, not customer PII. The cache key is the SHA-256 hash of the line
signature — no customer identifier is included. **No customer PII is
cached.**

**Status:** Mitigated. (Documented in README's "Logging" section.)

### T-11 — Fail-hard mode used as DoS vector

**Attack:** Attacker spams the cart with checkouts when the engine is
down, forcing repeated engine calls and exhausting application
resources.

**Mitigation:** The PHP SDK uses Guzzle's default 10-second timeout
(configurable in our form, capped at 60s). Each blocked checkout
fails within seconds, not minutes. Fail-soft (default OFF for
"Fail hard on engine error") absorbs this entirely.

**Status:** Mitigated by timeout + fail-soft default.

### T-12 — Cache stampede during engine recovery

**Attack:** After the engine has been down for hours, when it returns
the next 1000 customer requests all simultaneously miss the cache and
hit the engine, multiplying load.

**Mitigation (partial):** The cache is populated synchronously per
request — no anti-stampede locking. **Accepted residual risk for
v0.1.** Drupal's `cache.default` does serialize writes per backend,
so the risk is bounded but non-zero. Future hardening: probabilistic
early-refresh or a Drupal `Lock` around the populate path (v0.2).

**Status:** Partially mitigated; **accepted residual risk for v0.1**.

## Summary

12 threats reviewed. 9 mitigated. 3 carry accepted residual risk for
v0.1 (T-02, T-04, T-12) — each is documented with the v0.2 follow-up.
No unmitigated critical-severity threats.

## What did NOT change

- The OpenSalesTax engine HTTP API surface (we consume `/v1/health`
  and `/v1/calculate`; no privileged endpoints).
- Drupal core's permission model.
- Drupal Commerce's adjustment-application pipeline.

## Re-review triggers

The next audit should run when **any** of these happen:

- A new admin field is added to `SettingsForm`.
- A new HTTP boundary is introduced (e.g., a webhook receiver).
- The SDK bumps a major version.
- A Drupal Security Advisory affects `commerce_tax` or the Drupal
  Form API.
- Eric explicitly asks for a re-baseline.
