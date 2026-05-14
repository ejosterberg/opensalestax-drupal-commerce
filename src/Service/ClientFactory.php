<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\opensalestax_commerce\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\opensalestax_commerce\Exception\EngineNotConfiguredException;
use OpenSalesTax\Client;
use Psr\Http\Client\ClientInterface as PsrHttpClient;

/**
 * Builds a configured OpenSalesTax SDK Client from module settings.
 *
 * Centralizing construction here lets us inject the configured base URL,
 * API key, and timeout in one place — and lets tests swap in a custom
 * PSR-18 HTTP client without touching the calculator.
 */
class ClientFactory {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UrlValidator $urlValidator,
  ) {
  }

  /**
   * Builds an SDK client from the current module configuration.
   *
   * @param \Psr\Http\Client\ClientInterface|null $httpClient
   *   Optional PSR-18 HTTP client override (used in tests).
   *
   * @return \OpenSalesTax\Client
   *   The configured client.
   *
   * @throws \Drupal\opensalestax_commerce\Exception\EngineNotConfiguredException
   *   When the engine URL is empty.
   * @throws \Drupal\opensalestax_commerce\Exception\InvalidEngineUrlException
   *   When the engine URL is structurally invalid or fails SSRF defense.
   */
  public function create(?PsrHttpClient $httpClient = NULL): Client {
    $config = $this->configFactory->get('opensalestax_commerce.settings');
    $urlRaw = $config->get('api_url');
    $url = is_string($urlRaw) ? $urlRaw : '';
    if ($url === '') {
      throw new EngineNotConfiguredException('OpenSalesTax engine URL is not configured.');
    }

    $restrict = (bool) $config->get('restrict_to_public_ips');
    // Re-validate at runtime: defense against config-drift (admin
    // disabled the toggle then re-enabled it without re-saving the URL).
    $this->urlValidator->validate($url, $restrict);

    $apiKeyRaw = $config->get('api_key');
    $apiKey = is_string($apiKeyRaw) ? $apiKeyRaw : '';

    $timeoutRaw = $config->get('timeout_seconds');
    $timeout = is_int($timeoutRaw) || is_float($timeoutRaw) || (is_string($timeoutRaw) && ctype_digit($timeoutRaw))
      ? (int) $timeoutRaw
      : 10;

    return new Client(
      baseUrl: $url,
      apiKey: $apiKey === '' ? NULL : $apiKey,
      timeoutSeconds: (float) $timeout,
      httpClient: $httpClient,
    );
  }

}
