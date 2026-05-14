<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\Tests\opensalestax_commerce\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\opensalestax_commerce\Exception\EngineNotConfiguredException;
use Drupal\opensalestax_commerce\Exception\InvalidEngineUrlException;
use Drupal\opensalestax_commerce\Service\ClientFactory;
use Drupal\opensalestax_commerce\Service\UrlValidator;
use OpenSalesTax\Client;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Drupal\opensalestax_commerce\Service\ClientFactory
 */
final class ClientFactoryTest extends TestCase {

  public function testEmptyUrlThrows(): void {
    $factory = new ClientFactory($this->configMock(['api_url' => '']), new UrlValidator());
    $this->expectException(EngineNotConfiguredException::class);
    $factory->create();
  }

  public function testValidUrlBuildsClient(): void {
    $factory = new ClientFactory(
      $this->configMock(['api_url' => 'https://ost.example.com', 'api_key' => 'tok', 'timeout_seconds' => 5]),
      new UrlValidator(static fn () => '203.0.113.10')
    );
    self::assertInstanceOf(Client::class, $factory->create());
  }

  public function testEnforcesRestrictionAtRuntime(): void {
    $factory = new ClientFactory(
      $this->configMock([
        'api_url' => 'http://internal.lan',
        'restrict_to_public_ips' => TRUE,
      ]),
      new UrlValidator(static fn () => '10.0.0.5')
    );
    $this->expectException(InvalidEngineUrlException::class);
    $factory->create();
  }

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

}
