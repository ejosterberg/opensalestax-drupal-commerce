<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\Tests\opensalestax_commerce\Unit\Plugin\Commerce\TaxType;

use Drupal\opensalestax_commerce\Plugin\Commerce\TaxType\OpenSalesTax;
use Drupal\opensalestax_commerce\Service\TaxCalculator;
use OpenSalesTax\Responses\CalculateResponse;
use OpenSalesTax\Responses\CalculatedLine;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Drupal\opensalestax_commerce\Plugin\Commerce\TaxType\OpenSalesTax
 */
final class OpenSalesTaxTest extends TestCase {

  public function testAnnotationIsDiscoverable(): void {
    $reflection = new \ReflectionClass(OpenSalesTax::class);
    $docComment = $reflection->getDocComment();
    self::assertNotFalse($docComment);
    self::assertStringContainsString('@CommerceTaxType', $docComment);
    self::assertStringContainsString('id = "opensalestax"', $docComment);
    self::assertStringContainsString('label = @Translation(', $docComment);
  }

  public function testAppliesReturnsFalseForCanadianOrder(): void {
    $plugin = $this->makePlugin($this->stubCalculator(NULL));
    $order = $this->makeOrder('CA', '55401', 'USD', [['amount' => '100.00']]);
    self::assertFalse($plugin->applies($order));
  }

  public function testAppliesReturnsFalseForEurOrder(): void {
    $plugin = $this->makePlugin($this->stubCalculator(NULL));
    $order = $this->makeOrder('US', '55401', 'EUR', [['amount' => '100.00']]);
    self::assertFalse($plugin->applies($order));
  }

  public function testAppliesReturnsFalseForMissingZip(): void {
    $plugin = $this->makePlugin($this->stubCalculator(NULL));
    $order = $this->makeOrder('US', '', 'USD', [['amount' => '100.00']]);
    self::assertFalse($plugin->applies($order));
  }

  public function testAppliesReturnsFalseForMissingShippingProfile(): void {
    $plugin = $this->makePlugin($this->stubCalculator(NULL));
    $order = $this->makeOrder(NULL, NULL, 'USD', [['amount' => '100.00']]);
    self::assertFalse($plugin->applies($order));
  }

  public function testAppliesReturnsTrueForUsdUsOrder(): void {
    $plugin = $this->makePlugin($this->stubCalculator(NULL));
    $order = $this->makeOrder('US', '55401', 'USD', [['amount' => '100.00']]);
    self::assertTrue($plugin->applies($order));
  }

  public function testAppliesAcceptsZipPlus4(): void {
    $plugin = $this->makePlugin($this->stubCalculator(NULL));
    $order = $this->makeOrder('US', '55401-1234', 'USD', [['amount' => '100.00']]);
    self::assertTrue($plugin->applies($order));
  }

  public function testApplyDoesNothingWhenCalculatorReturnsNull(): void {
    $plugin = $this->makePlugin($this->stubCalculator(NULL));
    $order = $this->makeOrder('US', '55401', 'USD', [['amount' => '100.00']]);
    $plugin->apply($order);
    self::assertSame([], $order->adjustments);
  }

  public function testApplyExtractsZipPlus4AsFiveDigits(): void {
    $captured = [];
    $calculator = new class ($captured) extends TaxCalculator {

      /** @var array{country: string, currency: string, zip: string, lines: array<int, mixed>}|null */
      public ?array $captured = NULL;

      public function __construct(array &$captured) {
        $this->observed = &$captured;
      }

      public array $observed;

      public function calculateForOrder(string $country, string $currency, string $zip5, array $lines, ?\Psr\Http\Client\ClientInterface $httpClient = NULL): ?CalculateResponse {
        $this->observed[] = ['country' => $country, 'currency' => $currency, 'zip' => $zip5, 'lines' => $lines];
        return NULL;
      }

    };
    $plugin = $this->makePlugin($calculator);
    $order = $this->makeOrder('US', '55401-1234', 'USD', [['amount' => '100.00']]);
    $plugin->apply($order);
    self::assertNotEmpty($calculator->observed);
    self::assertSame('55401', $calculator->observed[0]['zip']);
    self::assertSame('US', $calculator->observed[0]['country']);
    self::assertSame('USD', $calculator->observed[0]['currency']);
    self::assertCount(1, $calculator->observed[0]['lines']);
  }

  public function testApplyWritesJurisdictionAdjustmentsWhenAvailable(): void {
    $this->loadCommerceStubs();
    $jur = new \stdClass();
    $jur->code = 'MN-state';
    $jur->name = 'Minnesota State Sales Tax';
    $jur->tax = '6.88';
    $jur->ratePct = '6.875';
    $line = new CalculatedLine(
      amount: '100.00',
      category: 'general',
      tax: '6.88',
      ratePct: '6.875',
      jurisdictions: [$jur],
    );
    $response = new CalculateResponse(subtotal: '100.00', taxTotal: '6.88', lines: [$line], disclaimer: '');
    $plugin = $this->makePlugin($this->stubCalculator($response));
    $order = $this->makeOrder('US', '55401', 'USD', [['amount' => '100.00']]);
    $plugin->apply($order);
    self::assertCount(1, $order->adjustments);
    self::assertSame('Minnesota State Sales Tax', $order->adjustments[0]->getDefinition()['label']);
    self::assertSame('opensalestax:MN-state', $order->adjustments[0]->getDefinition()['source_id']);
  }

  public function testApplyWritesCombinedAdjustmentWhenNoJurisdictions(): void {
    $this->loadCommerceStubs();
    $line = new CalculatedLine(
      amount: '100.00',
      category: 'general',
      tax: '8.88',
      ratePct: '8.88',
      jurisdictions: [],
    );
    $response = new CalculateResponse(subtotal: '100.00', taxTotal: '8.88', lines: [$line], disclaimer: '');
    $plugin = $this->makePlugin($this->stubCalculator($response));
    $order = $this->makeOrder('US', '55401', 'USD', [['amount' => '100.00']]);
    $plugin->apply($order);
    self::assertCount(1, $order->adjustments);
    self::assertSame('Sales tax', $order->adjustments[0]->getDefinition()['label']);
    self::assertSame('opensalestax', $order->adjustments[0]->getDefinition()['source_id']);
  }

  public function testApplySkipsLinesWithZeroTax(): void {
    $this->loadCommerceStubs();
    $line = new CalculatedLine(
      amount: '100.00',
      category: 'general',
      tax: '0',
      ratePct: '0',
      jurisdictions: [],
    );
    $response = new CalculateResponse(subtotal: '100.00', taxTotal: '0', lines: [$line], disclaimer: '');
    $plugin = $this->makePlugin($this->stubCalculator($response));
    $order = $this->makeOrder('US', '55401', 'USD', [['amount' => '100.00']]);
    $plugin->apply($order);
    self::assertSame([], $order->adjustments);
  }

  /**
   * Loads commerce_order / commerce_price stand-in classes for adjustment tests.
   *
   * Drupal core, commerce_order, and commerce_price are not pulled in by
   * our composer.json (drupal-module convention: the host site supplies
   * them). For the adjustment-writing tests we declare minimal stand-ins
   * the first time they are needed.
   */
  private function loadCommerceStubs(): void {
    if (class_exists('Drupal\\commerce_order\\Adjustment', FALSE)) {
      return;
    }
    eval(<<<'PHP'
namespace Drupal\commerce_price;
class Price {
  public function __construct(public string $number, public string $currency) {}
  public function getNumber(): string { return $this->number; }
  public function getCurrencyCode(): string { return $this->currency; }
}
namespace Drupal\commerce_order;
class Adjustment {
  /** @var array<string, mixed> */
  private array $def;
  /** @param array<string, mixed> $def */
  public function __construct(array $def) { $this->def = $def; }
  /** @return array<string, mixed> */
  public function getDefinition(): array { return $this->def; }
}
PHP
    );
  }

  public function testApplyHandlesEmptyLineItems(): void {
    $plugin = $this->makePlugin($this->stubCalculator(NULL));
    $order = $this->makeOrder('US', '55401', 'USD', []);
    $plugin->apply($order);
    self::assertSame([], $order->adjustments);
  }

  public function testApplyExtractsAmountFromAdjustedTotalPriceWhenAvailable(): void {
    $observed = [];
    $calculator = new class ($observed) extends TaxCalculator {

      /** @var array<int, array<string, mixed>> */
      public array $observed;
      public function __construct(array &$observed) {
        $this->observed = &$observed;
      }

      public function calculateForOrder(string $country, string $currency, string $zip5, array $lines, ?\Psr\Http\Client\ClientInterface $httpClient = NULL): ?CalculateResponse {
        $this->observed[] = $lines;
        return NULL;
      }

    };
    $plugin = $this->makePlugin($calculator);
    $order = $this->makeOrder('US', '55401', 'USD', [
      ['amount' => '100.00', 'adjusted_amount' => '90.00'],
    ]);
    $plugin->apply($order);
    // Prefers adjusted_amount when present.
    self::assertSame('90.00', $calculator->observed[0][0]['amount']);
  }

  // ---- helpers ----

  /**
   * Builds a fake Drupal Commerce order.
   *
   * @param string|null $country
   *   Shipping profile country code, or NULL for "no profile".
   * @param string|null $postal
   *   Shipping postal code, or NULL for "no profile".
   * @param string $currency
   *   Currency code.
   * @param array<int, array<string, string>> $lines
   *   Line item shapes.
   *
   * @return object
   *   A duck-typed order object.
   */
  private function makeOrder(?string $country, ?string $postal, string $currency, array $lines): object {
    $address = $country === NULL ? NULL : new class ($country, $postal ?? '') {

      public function __construct(private string $country, private string $postal) {}

      public function getCountryCode(): string {
        return $this->country;
      }

      public function getPostalCode(): string {
        return $this->postal;
      }

    };
    $field = $address === NULL ? NULL : new class ($address) {

      /**
       * @param object $address
       */
      public function __construct(private object $address) {}

      public function isEmpty(): bool {
        return FALSE;
      }

      public function first(): object {
        return $this->address;
      }

    };
    $profile = $field === NULL ? NULL : new class ($field) {

      public function __construct(private object $field) {}

      public function get(string $name): ?object {
        return $name === 'address' ? $this->field : NULL;
      }

    };
    $items = [];
    foreach ($lines as $line) {
      $items[] = new class ($line) {

        /**
         * @param array<string, string> $shape
         */
        public function __construct(private array $shape) {}

        public function id(): string {
          return 'oi-' . spl_object_hash($this);
        }

        public function getTotalPrice(): object {
          return new class ($this->shape['amount']) {

            public function __construct(private string $amount) {}

            public function getNumber(): string {
              return $this->amount;
            }

          };
        }

        public function getAdjustedTotalPrice(): ?object {
          if (!isset($this->shape['adjusted_amount'])) {
            return NULL;
          }
          $amount = $this->shape['adjusted_amount'];
          return new class ($amount) {

            public function __construct(private string $amount) {}

            public function getNumber(): string {
              return $this->amount;
            }

          };
        }

      };
    }
    $totalPrice = new class ($currency) {

      public function __construct(private string $currency) {}

      public function getCurrencyCode(): string {
        return $this->currency;
      }

    };
    return new class ($profile, $totalPrice, $items) {

      /** @var array<int, object> */
      public array $adjustments = [];

      /**
       * @param array<int, object> $items
       */
      public function __construct(private ?object $shipping, private object $totalPrice, private array $items) {}

      /**
       * @return array<string, object>
       */
      public function collectProfiles(): array {
        if ($this->shipping === NULL) {
          return [];
        }
        return ['shipping' => $this->shipping, 'billing' => $this->shipping];
      }

      public function getBillingProfile(): ?object {
        return $this->shipping;
      }

      public function getTotalPrice(): object {
        return $this->totalPrice;
      }

      /**
       * @return array<int, object>
       */
      public function getItems(): array {
        return $this->items;
      }

      public function addAdjustment(object $adjustment): void {
        $this->adjustments[] = $adjustment;
      }

    };
  }

  private function stubCalculator(?CalculateResponse $response): TaxCalculator {
    return new class ($response) extends TaxCalculator {

      public function __construct(private ?CalculateResponse $response) {}

      public function calculateForOrder(string $country, string $currency, string $zip5, array $lines, ?\Psr\Http\Client\ClientInterface $httpClient = NULL): ?CalculateResponse {
        return $this->response;
      }

    };
  }

  private function makePlugin(TaxCalculator $calculator): OpenSalesTax {
    $entityTypeManager = $this->createStub(\Drupal\Core\Entity\EntityTypeManagerInterface::class);
    $eventDispatcher = $this->createStub(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
    return new OpenSalesTax([], 'opensalestax', NULL, $entityTypeManager, $eventDispatcher, $calculator);
  }

}
