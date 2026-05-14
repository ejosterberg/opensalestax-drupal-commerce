<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\opensalestax_commerce\Service;

use Drupal\opensalestax_commerce\Exception\InvalidEngineUrlException;

/**
 * Server-side SSRF-defense validator for the engine base URL.
 *
 * Two checks always run:
 *  1. The value parses as a URL with both scheme and host present.
 *  2. The scheme is `http` or `https`.
 *
 * One optional check (gated by $restrictToPublicIps):
 *  3. The host resolves to a non-private, non-reserved, non-loopback,
 *     non-CGNAT, non-link-local, non-multicast IP.
 *
 * Defense-in-depth motivation: even if an admin mistakenly enters
 * `http://localhost`, `http://10.0.0.5`, `http://169.254.169.254`
 * (cloud metadata), or `http://100.64.0.1` (CGNAT), the URL validator
 * blocks it when restrict-to-public-IPs is on.
 *
 * The validator returns the resolved IP when restrict-to-public-IPs is
 * on and validation succeeds, so the caller can pin it (defeating
 * DNS-rebinding by a malicious DNS provider). Returns NULL when
 * pinning is not required (empty input, or restrict-to-public-IPs
 * off — Drupal will use the OS resolver).
 */
class UrlValidator {

  /**
   * Optional resolver override (for tests).
   *
   * @var callable|null
   */
  private $hostResolver;

  /**
   * Constructs the validator.
   *
   * @param callable(string):?string|null $hostResolver
   *   Function returning the resolved IP for a hostname, or NULL on
   *   failure. The default uses gethostbyname(); tests pass a
   *   deterministic mock to avoid network lookups.
   */
  public function __construct(?callable $hostResolver = NULL) {
    $this->hostResolver = $hostResolver ?? static function (string $host): ?string {
      if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
        return $host;
      }
      $resolved = gethostbyname($host);
      return $resolved === $host ? NULL : $resolved;
    };
  }

  /**
   * Validates a URL and (optionally) resolves it to a pinnable IP.
   *
   * @param string $url
   *   The URL to validate. Empty string is valid (module inert).
   * @param bool $restrictToPublicIps
   *   When TRUE, the resolved IP must be publicly routable.
   *
   * @return string|null
   *   The resolved IP for DNS pinning, or NULL when no pin is needed.
   *
   * @throws \Drupal\opensalestax_commerce\Exception\InvalidEngineUrlException
   *   When validation fails.
   */
  public function validate(string $url, bool $restrictToPublicIps): ?string {
    if ($url === '') {
      return NULL;
    }

    $parts = parse_url($url);
    if ($parts === FALSE || !isset($parts['scheme'], $parts['host'])) {
      throw new InvalidEngineUrlException(
        'The Engine API URL must be a fully-qualified URL (e.g. https://ost.example.com).'
      );
    }

    if (!in_array($parts['scheme'], ['http', 'https'], TRUE)) {
      throw new InvalidEngineUrlException(
        'The Engine API URL must use http or https.'
      );
    }

    if ($parts['host'] === '') {
      throw new InvalidEngineUrlException(
        'The Engine API URL must include a hostname.'
      );
    }

    if (!$restrictToPublicIps) {
      return NULL;
    }

    return $this->resolveAndCheckPublic($parts['host']);
  }

  /**
   * Resolves a host and verifies it is publicly routable.
   *
   * @param string $host
   *   The hostname or IP literal to check.
   *
   * @return string
   *   The resolved IP (caller may pin it).
   *
   * @throws \Drupal\opensalestax_commerce\Exception\InvalidEngineUrlException
   *   When the host fails to resolve or resolves to a non-public IP.
   */
  private function resolveAndCheckPublic(string $host): string {
    $resolver = $this->hostResolver;
    /** @var string|null $ip */
    $ip = $resolver($host);
    if ($ip === NULL) {
      throw new InvalidEngineUrlException(
        'The Engine API URL host could not be resolved.'
      );
    }
    $isPublic = filter_var(
      $ip,
      FILTER_VALIDATE_IP,
      FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    if ($isPublic === FALSE || $this->isAdditionallyReserved($ip)) {
      throw new InvalidEngineUrlException(
        'The Engine API URL must resolve to a public IP when "Restrict to public IPs" is enabled. '
        . 'Disable this option only when the engine is on the same private network as Drupal.'
      );
    }
    return $ip;
  }

  /**
   * Checks ranges PHP's filter_var doesn't reject by default.
   *
   * Augments FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE with explicit
   * rejection of:
   *  - CGNAT: 100.64.0.0/10 (RFC 6598)
   *  - Multicast: 224.0.0.0/4 (RFC 5771)
   *  - Reserved future use: 240.0.0.0/4 (RFC 1112) — already covered
   *    by NO_RES_RANGE on some PHP versions but defensive
   *  - IPv4-mapped IPv6 like ::ffff:10.0.0.5 should already be rejected
   *    by NO_PRIV_RANGE; we leave that to PHP.
   *
   * @param string $ip
   *   Resolved IP.
   *
   * @return bool
   *   TRUE when the IP belongs to a range we explicitly reject.
   */
  private function isAdditionallyReserved(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === FALSE) {
      return FALSE;
    }
    $packed = inet_pton($ip);
    if ($packed === FALSE || strlen($packed) !== 4) {
      return FALSE;
    }
    $long = unpack('N', $packed);
    if ($long === FALSE || !isset($long[1])) {
      return FALSE;
    }
    $n = $long[1];

    // CGNAT: 100.64.0.0/10 → 100.64.0.0 .. 100.127.255.255.
    if (($n & 0xFFC00000) === 0x64400000) {
      return TRUE;
    }
    // Multicast: 224.0.0.0/4.
    if (($n & 0xF0000000) === 0xE0000000) {
      return TRUE;
    }
    // Reserved future-use: 240.0.0.0/4 (excluding 255.255.255.255 broadcast).
    if (($n & 0xF0000000) === 0xF0000000) {
      return TRUE;
    }
    return FALSE;
  }

}
