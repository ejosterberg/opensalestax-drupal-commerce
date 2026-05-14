<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\Tests\opensalestax_commerce\Unit\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\opensalestax_commerce\Service\ClientFactory;
use Drupal\opensalestax_commerce\Service\TaxCalculator;
use Drupal\opensalestax_commerce\Service\UrlValidator;
use GuzzleHttp\Psr7\Response;
use OpenSalesTax\Exceptions\OpenSalesTaxApiException;
use OpenSalesTax\Exceptions\OpenSalesTaxNetworkException;
use OpenSalesTax\Responses\CalculateResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as PsrHttpClient;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * @covers \Drupal\opensalestax_commerce\Service\TaxCalculator
 */
final class TaxCalculatorTest extends TestCase {

  public function testNonUsCountryReturnsNull(): void {
    $calc = $this->makeCalculator($this->configMock([]), $this->stubHttp(200, '{"subtotal":"0","tax_total":"0","lines":[]}'));
    self::assertNull($calc->calculateForOrder('CA', 'USD', '55401', [$this->line('100.00')]));
  }

  public function testNonUsdCurrencyReturnsNull(): void {
    $calc = $this->makeCalculator($this->configMock([]), $this->stubHttp(200, '{}'));
    self::assertNull($calc->calculateForOrder('US', 'EUR', '55401', [$this->line('100.00')]));
  }

  public function testInvalidZip5ReturnsNull(): void {
    $calc = $this->makeCalculator($this->configMock([]), $this->stubHttp(200, '{}'));
    self::assertNull($calc->calculateForOrder('US', 'USD', '5540', [$this->line('100.00')]));
    self::assertNull($calc->calculateForOrder('US', 'USD', 'ABCDE', [$this->line('100.00')]));
    self::assertNull($calc->calculateForOrder('US', 'USD', '', [$this->line('100.00')]));
  }

  public function testEmptyLinesReturnsNull(): void {
    $calc = $this->makeCalculator($this->configMock([]), $this->stubHttp(200, '{}'));
    self::assertNull($calc->calculateForOrder('US', 'USD', '55401', []));
  }

  public function testUnconfiguredUrlReturnsNullSilently(): void {
    $calc = $this->makeCalculator($this->configMock(['api_url' => '']), $this->stubHttp(200, '{}'));
    self::assertNull($calc->calculateForOrder('US', 'USD', '55401', [$this->line('100.00')]));
  }

  public function testHappyPathReturnsResponse(): void {
    $body = json_encode([
      'subtotal' => '100.00',
      'tax_total' => '8.78',
      'lines' => [['amount' => '100.00', 'category' => 'general', 'tax' => '8.78', 'rate_pct' => '8.78', 'jurisdictions' => []]],
      'disclaimer' => 'as-is',
    ]);
    $calc = $this->makeCalculator($this->configMock([]), $this->stubHttp(200, $body !== FALSE ? $body : '{}'));
    $response = $calc->calculateForOrder('US', 'USD', '55401', [$this->line('100.00')]);
    self::assertInstanceOf(CalculateResponse::class, $response);
    self::assertSame('8.78', $response->taxTotal);
  }

  public function testFailSoftOnApiError(): void {
    $http = $this->stubHttp(503, '{"error":"engine down"}');
    $calc = $this->makeCalculator($this->configMock(['fail_hard' => FALSE]), $http);
    self::assertNull($calc->calculateForOrder('US', 'USD', '55401', [$this->line('100.00')]));
  }

  public function testFailHardOnApiError(): void {
    $http = $this->stubHttp(503, '{"error":"engine down"}');
    $calc = $this->makeCalculator($this->configMock(['fail_hard' => TRUE]), $http);
    $this->expectException(OpenSalesTaxApiException::class);
    $calc->calculateForOrder('US', 'USD', '55401', [$this->line('100.00')]);
  }

  public function testFailSoftOnNetworkError(): void {
    $http = new class implements PsrHttpClient {

      public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface {
        throw new class('boom') extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
      }

    };
    $calc = $this->makeCalculator($this->configMock(['fail_hard' => FALSE]), $http);
    self::assertNull($calc->calculateForOrder('US', 'USD', '55401', [$this->line('100.00')]));
  }

  public function testFailHardOnNetworkError(): void {
    $http = new class implements PsrHttpClient {

      public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface {
        throw new class('boom') extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
      }

    };
    $calc = $this->makeCalculator($this->configMock(['fail_hard' => TRUE]), $http);
    $this->expectException(OpenSalesTaxNetworkException::class);
    $calc->calculateForOrder('US', 'USD', '55401', [$this->line('100.00')]);
  }

  public function testCacheHitSkipsHttp(): void {
    $cached = new CalculateResponse(subtotal: '50.00', taxTotal: '4.00', lines: [], disclaimer: '');
    $cache = new class ($cached) implements CacheBackendInterface {

      public int $getCount = 0;
      public int $setCount = 0;
      public function __construct(private \OpenSalesTax\Responses\CalculateResponse $stored) {}

      public function get(string $cid, bool $allow_invalid = FALSE): false|object {
        $this->getCount++;
        return (object) ['data' => $this->stored];
      }

      public function set(string $cid, mixed $data, int $expire = -1, array $tags = []): void {
        $this->setCount++;
      }

    };
    $http = $this->stubHttp(500, '{}'); // would error if called.
    $calc = new TaxCalculator(
      $this->factoryFromHttp($http, $this->configMock([])),
      $this->configMock([]),
      $cache,
      new NullLogger()
    );
    $response = $calc->calculateForOrder('US', 'USD', '55401', [$this->line('50.00')]);
    self::assertSame('4.00', $response?->taxTotal);
    self::assertSame(1, $cache->getCount);
    self::assertSame(0, $cache->setCount);
  }

  public function testCacheMissPopulatesCache(): void {
    $cache = new class implements CacheBackendInterface {

      public int $getCount = 0;
      public int $setCount = 0;

      public function get(string $cid, bool $allow_invalid = FALSE): false|object {
        $this->getCount++;
        return FALSE;
      }

      public function set(string $cid, mixed $data, int $expire = -1, array $tags = []): void {
        $this->setCount++;
      }

    };
    $body = json_encode([
      'subtotal' => '100.00',
      'tax_total' => '8.78',
      'lines' => [],
      'disclaimer' => '',
    ]);
    $http = $this->stubHttp(200, $body !== FALSE ? $body : '{}');
    $calc = new TaxCalculator(
      $this->factoryFromHttp($http, $this->configMock([])),
      $this->configMock([]),
      $cache,
      new NullLogger()
    );
    $calc->calculateForOrder('US', 'USD', '55401', [$this->line('100.00')]);
    self::assertSame(1, $cache->getCount);
    self::assertSame(1, $cache->setCount);
  }

  public function testCacheKeyDiffersByZip(): void {
    $observed = [];
    $cache = new class ($observed) implements CacheBackendInterface {

      /** @var array<int, string> */
      public array $observed;
      public function __construct(array &$observed) {
        $this->observed = &$observed;
      }

      public function get(string $cid, bool $allow_invalid = FALSE): false|object {
        $this->observed[] = $cid;
        return FALSE;
      }

      public function set(string $cid, mixed $data, int $expire = -1, array $tags = []): void {
      }

    };
    $http = $this->stubHttp(200, '{"subtotal":"0","tax_total":"0","lines":[],"disclaimer":""}');
    $calc = new TaxCalculator(
      $this->factoryFromHttp($http, $this->configMock([])),
      $this->configMock([]),
      $cache,
      new NullLogger()
    );
    $calc->calculateForOrder('US', 'USD', '55401', [$this->line('100.00')]);
    $calc->calculateForOrder('US', 'USD', '90210', [$this->line('100.00')]);
    self::assertCount(2, $observed);
    self::assertNotSame($observed[0], $observed[1]);
  }

  public function testInvalidUrlDuringCalculateReturnsNull(): void {
    // restrict_to_public_ips on, host resolves to private → factory throws.
    $config = $this->configMock(['api_url' => 'http://10.0.0.5:8080', 'restrict_to_public_ips' => TRUE, 'fail_hard' => FALSE]);
    $http = $this->stubHttp(200, '{}');
    $factory = new ClientFactory($config, new UrlValidator(static fn () => '10.0.0.5'));
    $calc = new TaxCalculator($factory, $config, $this->memCache(), new NullLogger());
    // InvalidEngineUrlException is a configuration error, not an SDK
    // exception — fail-soft swallows it.
    self::assertNull($calc->calculateForOrder('US', 'USD', '55401', [$this->line('1.00')]));
  }

  // ---- helpers ----

  /**
   * @param array<string, mixed> $overrides
   */
  private function configMock(array $overrides): ConfigFactoryInterface {
    $defaults = [
      'api_url' => 'https://ost.example.com',
      'api_key' => '',
      'restrict_to_public_ips' => FALSE,
      'cache_ttl_seconds' => 86400,
      'fail_hard' => FALSE,
      'timeout_seconds' => 10,
    ];
    $values = array_merge($defaults, $overrides);
    return new class ($values) implements ConfigFactoryInterface {

      /** @var array<string, mixed> */
      private array $values;
      /**
       * @param array<string, mixed> $values
       */
      public function __construct(array $values) {
        $this->values = $values;
      }

      public function get(string $name): object {
        $values = $this->values;
        return new class ($values) {

          /** @var array<string, mixed> */
          private array $v;
          /**
           * @param array<string, mixed> $v
           */
          public function __construct(array $v) {
            $this->v = $v;
          }

          public function get(string $key): mixed {
            return $this->v[$key] ?? NULL;
          }

        };
      }

      public function getEditable(string $name): object {
        return $this->get($name);
      }

    };
  }

  private function stubHttp(int $status, string $body): PsrHttpClient {
    return new class ($status, $body) implements PsrHttpClient {

      public function __construct(private int $status, private string $body) {}

      public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface {
        return new Response($this->status, ['Content-Type' => 'application/json'], $this->body);
      }

    };
  }

  private function memCache(): CacheBackendInterface {
    return new class implements CacheBackendInterface {

      /** @var array<string, object> */
      private array $store = [];

      public function get(string $cid, bool $allow_invalid = FALSE): false|object {
        return $this->store[$cid] ?? FALSE;
      }

      public function set(string $cid, mixed $data, int $expire = -1, array $tags = []): void {
        $this->store[$cid] = (object) ['data' => $data];
      }

    };
  }

  private function factoryFromHttp(PsrHttpClient $http, ConfigFactoryInterface $config): ClientFactory {
    return new class ($config, new UrlValidator(static fn () => '203.0.113.10'), $http) extends ClientFactory {

      public function __construct(
        ConfigFactoryInterface $cf,
        UrlValidator $uv,
        private PsrHttpClient $http,
      ) {
        parent::__construct($cf, $uv);
      }

      public function create(?PsrHttpClient $httpClient = NULL): \OpenSalesTax\Client {
        return parent::create($this->http);
      }

    };
  }

  private function makeCalculator(ConfigFactoryInterface $config, PsrHttpClient $http): TaxCalculator {
    return new TaxCalculator(
      $this->factoryFromHttp($http, $config),
      $config,
      $this->memCache(),
      new NullLogger()
    );
  }

  /**
   * @return array{amount: string, category: string}
   */
  private function line(string $amount, string $category = 'general'): array {
    return ['amount' => $amount, 'category' => $category];
  }

}
