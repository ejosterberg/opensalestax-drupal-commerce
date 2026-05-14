# Security Policy

## Reporting a vulnerability

Email **ejosterberg@gmail.com** with the details. Use the subject line
`[security] opensalestax-drupal-commerce <one-line summary>`. Please
do **not** open a public GitHub issue for security disclosures.

I aim to acknowledge within 72 hours, share an initial impact
assessment within one week, and ship a patch tag within two weeks
for confirmed issues. Coordinated-disclosure timelines are negotiable
in good faith if you need more time to release advisories.

## Scope

In scope:

- The connector module code (everything under `src/`).
- Configuration storage and validation paths.
- The HTTP boundary between Drupal and the OpenSalesTax engine.

Out of scope:

- The [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax)
  itself — report engine vulnerabilities there.
- Drupal core and Drupal Commerce — report those to
  [Drupal.org Security Team](https://www.drupal.org/security-team).
- The [PHP SDK](https://github.com/ejosterberg/opensalestax-php) —
  report there.

## Supported versions

The current `0.1.x` line receives security patches. Older tagged
pre-releases (`0.1.0-alpha.N`) do not — please upgrade.

## Threat model

A summary lives in [`docs/SECURITY-REVIEW.md`](docs/SECURITY-REVIEW.md).
Read that before reporting to understand what's already mitigated and
what's accepted-risk.

## Disclosure credit

Reporters are credited in the CHANGELOG and (optionally) in a release
note. If you prefer to be anonymous, say so in the report.
