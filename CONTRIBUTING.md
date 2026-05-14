# Contributing

Thanks for your interest in improving OpenSalesTax for Drupal Commerce.

## Branch model

- `main` is the always-shippable branch.
- Feature work lands on `feature/<short-slug>` and is merged via PR.
- Release tags are `vX.Y.Z[-alpha.N]` per Semantic Versioning.

## DCO sign-off

Every commit MUST be signed off (Developer Certificate of Origin):

```bash
git commit -s -m "Your message"
```

This appends a `Signed-off-by:` trailer. CI rejects commits without
one. By signing off, you certify that you wrote (or have the right
to submit) the contribution under the project's license (Apache-2.0).

## No AI co-author trailers

Do **not** add `Co-Authored-By: Claude` (or any other AI coauthor)
trailer to commits. Author attribution is one human per commit; AI
assistance is a tool, not an author.

## Quality gate (must pass before merge)

```bash
composer install
composer test         # PHPUnit unit suite (all green required)
composer stan         # PHPStan level max (0 errors required)
composer audit        # composer audit (HIGH+ blocks merge)
```

CI runs these on every push and PR. If you add behavior, add tests
that cover the gates (US-only, USD-only, ZIP shape), the cache (hit
+ miss), the fail-soft/fail-hard branch, and any new SSRF case.

## Pull request expectations

1. Branch off `main`.
2. Implement + test + lint.
3. Update `CHANGELOG.md` under `[Unreleased]` describing the user-
   visible change.
4. Open the PR; fill in the description (what + why; not how).
5. Address review feedback by amending or appending commits as
   appropriate — all commits must remain signed-off.

## Reporting security issues

See [`SECURITY.md`](SECURITY.md). Do not open public issues for
security disclosures.

## Code style

- PSR-12 + Drupal coding standard (run `phpcs --standard=Drupal src`
  before pushing if you have `drupal/coder` installed locally).
- Strict types (`declare(strict_types=1);`) at the top of every PHP
  file.
- SPDX header `// SPDX-License-Identifier: Apache-2.0` at the top of
  every PHP file, immediately after the `<?php` opening tag.
- Drupal indentation: 2 spaces.
- Constructor property promotion is fine (PHP 8.1+).
- Final classes preferred for non-plugin services.
