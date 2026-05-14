<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\opensalestax_commerce\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\opensalestax_commerce\Exception\EngineNotConfiguredException;
use Drupal\opensalestax_commerce\Exception\InvalidEngineUrlException;
use OpenSalesTax\Address;
use OpenSalesTax\Exceptions\OpenSalesTaxApiException;
use OpenSalesTax\Exceptions\OpenSalesTaxNetworkException;
use OpenSalesTax\Exceptions\OpenSalesTaxValidationException;
use OpenSalesTax\LineItem;
use OpenSalesTax\Responses\CalculateResponse;
use Psr\Http\Client\ClientInterface as PsrHttpClient;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates a tax calculation: gates → SDK call → cache.
 *
 * This class is the integration "seam" between Drupal's domain and the
 * OpenSalesTax SDK. The tax-type plugin calls calculateForOrder() with
 * a normalized payload (country, currency, zip5, line items) and gets
 * back either a CalculateResponse or NULL (gate failed or fail-soft).
 *
 * Fail-soft policy: if `fail_hard` config is FALSE (default), every
 * SDK error is logged at WARNING and the method returns NULL. The
 * caller then writes zero adjustments — checkout proceeds. If
 * `fail_hard` is TRUE, the original exception propagates and Drupal
 * Commerce surfaces it to the customer.
 */
class TaxCalculator {

  /**
   * Engine call cache TTL hard ceiling (1 hour minimum, 7 days max).
   */
  private const TTL_MIN = 3600;
  private const TTL_MAX = 604800;

  public function __construct(
    private readonly ClientFactory $clientFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Calculates tax for an order's shipping address + line items.
   *
   * @param string $country
   *   ISO 3166-1 alpha-2 country code from the shipping address.
   * @param string $currency
   *   ISO 4217 currency code from the order total price.
   * @param string $zip5
   *   The 5-digit US ZIP from the shipping address (may be empty when
   *   the customer hasn't entered an address yet).
   * @param array<int, array{amount: string, category: string}> $lines
   *   Normalized line items: amount as decimal string, category as
   *   engine-recognized string.
   * @param \Psr\Http\Client\ClientInterface|null $httpClient
   *   Optional PSR-18 override (tests inject a recording mock).
   *
   * @return \OpenSalesTax\Responses\CalculateResponse|null
   *   The calculation result, or NULL when a gate failed or fail-soft
   *   absorbed an engine error.
   *
   * @throws \OpenSalesTax\Exceptions\OpenSalesTaxApiException
   *   Rethrown only when fail_hard is TRUE.
   * @throws \OpenSalesTax\Exceptions\OpenSalesTaxNetworkException
   *   Rethrown only when fail_hard is TRUE.
   */
  public function calculateForOrder(
    string $country,
    string $currency,
    string $zip5,
    array $lines,
    ?PsrHttpClient $httpClient = NULL,
  ): ?CalculateResponse {
    // Gate 1: US-only.
    if (strtoupper($country) !== 'US') {
      return NULL;
    }
    // Gate 2: USD-only.
    if (strtoupper($currency) !== 'USD') {
      return NULL;
    }
    // Gate 3: ZIP must be 5 digits (engine requirement).
    if (preg_match('/^\d{5}$/', $zip5) !== 1) {
      return NULL;
    }
    // Gate 4: at least one line.
    if ($lines === []) {
      return NULL;
    }

    $cacheKey = $this->buildCacheKey($zip5, $lines);
    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE && $cached->data instanceof CalculateResponse) {
      return $cached->data;
    }

    try {
      $client = $this->clientFactory->create($httpClient);
      $address = new Address($zip5);
      $lineObjects = [];
      foreach ($lines as $line) {
        $lineObjects[] = new LineItem(
          amount: $line['amount'],
          category: $line['category'] ?? 'general',
        );
      }
      $response = $client->calculate($address, $lineObjects);
    }
    catch (EngineNotConfiguredException $e) {
      // Inert: silently skip calculation; never log this every cart.
      return NULL;
    }
    catch (InvalidEngineUrlException $e) {
      // Config-time error reaching runtime — admin saved a URL with
      // restrict-to-public-IPs off, then re-enabled it. Log once per
      // checkout and fail-soft regardless of fail_hard so checkout
      // doesn't break across a config-recovery window.
      $this->logger->warning('opensalestax: engine URL rejected at runtime; check Restrict to public IPs setting: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
    catch (OpenSalesTaxValidationException $e) {
      $this->logger->warning('opensalestax: payload validation failed (zip5=@zip): @msg', [
        '@zip' => $zip5,
        '@msg' => $e->getMessage(),
      ]);
      return $this->failSoftOrThrow($e);
    }
    catch (OpenSalesTaxApiException $e) {
      $this->logger->warning('opensalestax: engine API error (status=@status, zip5=@zip): @msg', [
        '@status' => (string) $e->statusCode,
        '@zip' => $zip5,
        '@msg' => $e->getMessage(),
      ]);
      return $this->failSoftOrThrow($e);
    }
    catch (OpenSalesTaxNetworkException $e) {
      $this->logger->warning('opensalestax: engine network error (zip5=@zip): @msg', [
        '@zip' => $zip5,
        '@msg' => $e->getMessage(),
      ]);
      return $this->failSoftOrThrow($e);
    }

    $this->cache->set(
      $cacheKey,
      $response,
      $this->cacheExpiresAt(),
      ['opensalestax']
    );
    return $response;
  }

  /**
   * Builds a deterministic cache key from ZIP + line shape.
   *
   * @param string $zip5
   *   5-digit ZIP.
   * @param array<int, array{amount: string, category: string}> $lines
   *   Line items.
   *
   * @return string
   *   Cache key.
   */
  private function buildCacheKey(string $zip5, array $lines): string {
    $signature = [];
    foreach ($lines as $line) {
      $signature[] = ($line['category'] ?? 'general') . ':' . $line['amount'];
    }
    sort($signature);
    return 'opensalestax:' . $zip5 . ':' . hash('sha256', implode('|', $signature));
  }

  /**
   * Computes the cache expiry timestamp from configured TTL.
   *
   * @return int
   *   Unix timestamp at which the cache entry expires.
   */
  private function cacheExpiresAt(): int {
    $ttl = (int) $this->configFactory
      ->get('opensalestax_commerce.settings')
      ->get('cache_ttl_seconds');
    if ($ttl < self::TTL_MIN) {
      $ttl = self::TTL_MIN;
    }
    if ($ttl > self::TTL_MAX) {
      $ttl = self::TTL_MAX;
    }
    return time() + $ttl;
  }

  /**
   * Returns NULL when fail-soft, otherwise rethrows.
   *
   * @param \Throwable $e
   *   The error caught from the SDK.
   *
   * @return null
   *   Always NULL (when fail-soft); never returns when fail-hard.
   *
   * @throws \Throwable
   *   The original exception, unchanged, when fail_hard is TRUE.
   */
  private function failSoftOrThrow(\Throwable $e): ?CalculateResponse {
    $failHard = (bool) $this->configFactory
      ->get('opensalestax_commerce.settings')
      ->get('fail_hard');
    if ($failHard) {
      throw $e;
    }
    return NULL;
  }

}
