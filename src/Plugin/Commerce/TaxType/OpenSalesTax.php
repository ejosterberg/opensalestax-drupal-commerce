<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\opensalestax_commerce\Plugin\Commerce\TaxType;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_tax\Plugin\Commerce\TaxType\RemoteTaxTypeBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\opensalestax_commerce\Service\TaxCalculator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Drupal Commerce tax type plugin: OpenSalesTax.
 *
 * @CommerceTaxType(
 *   id = "opensalestax",
 *   label = @Translation("OpenSalesTax (Destination-Based US Sales Tax)"),
 *   weight = 0,
 * )
 *
 * Lifecycle:
 *  - Drupal Commerce's tax pipeline calls applies($order) to decide
 *    whether this tax type is relevant to the order.
 *  - If applies() is TRUE, apply($order) is called to write adjustments.
 *
 * v0.1 behavior:
 *  - applies() returns TRUE when the order is US-bound, USD, and has
 *    line items with a 5-digit shipping ZIP. Other orders fall through
 *    (Drupal Commerce's built-in tax types or no tax at all handle them).
 *  - apply() extracts the normalized payload from the order, hands it
 *    to TaxCalculator, and writes a per-jurisdiction adjustment for each
 *    jurisdiction returned. Falls back to a single combined adjustment
 *    when the engine response has no jurisdiction breakdown.
 *
 * Extends RemoteTaxTypeBase to satisfy Drupal Commerce's TaxTypeInterface
 * contract; overrides applies() + apply() with our fail-soft, ZIP-only logic.
 */
class OpenSalesTax extends RemoteTaxTypeBase {

  /**
   * The injected tax calculator service.
   *
   * @var \Drupal\opensalestax_commerce\Service\TaxCalculator
   */
  protected TaxCalculator $calculator;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EventDispatcherInterface $event_dispatcher,
    ?TaxCalculator $calculator = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $event_dispatcher);
    if ($calculator !== NULL) {
      $this->calculator = $calculator;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration = [], $plugin_id = '', $plugin_definition = NULL): self {
    $calculator = $container->get('opensalestax_commerce.tax_calculator');
    assert($calculator instanceof TaxCalculator);
    $definition = is_array($plugin_definition) ? $plugin_definition : ['id' => $plugin_id, 'label' => 'OpenSalesTax'];
    return new self(
      $configuration,
      (string) $plugin_id,
      $definition,
      $container->get('entity_type.manager'),
      $container->get('event_dispatcher'),
      $calculator
    );
  }

  /**
   * Decides whether this tax type applies to the given order.
   *
   * Pure inspection: we never call the engine here. The gate logic
   * matches TaxCalculator's gate but short-circuits earlier so we
   * don't waste a service-container lookup on Canadian / EUR orders.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Drupal Commerce order.
   *
   * @return bool
   *   TRUE if the order is eligible for OpenSalesTax calculation.
   */
  public function applies(OrderInterface $order) {
    $shipping = $this->extractShippingPostalContext($order);
    if ($shipping === NULL) {
      return FALSE;
    }
    [$country, $zip5] = $shipping;
    if (strtoupper($country) !== 'US') {
      return FALSE;
    }
    if (preg_match('/^\d{5}$/', $zip5) !== 1) {
      return FALSE;
    }
    return strtoupper($this->extractCurrency($order)) === 'USD';
  }

  /**
   * Applies tax adjustments to the order.
   *
   * Called by Drupal Commerce's tax pipeline after applies() returns
   * TRUE. We extract the shipping ZIP + line totals, call the calculator,
   * and append adjustments. On fail-soft (calculator returns NULL) we
   * append nothing — Drupal Commerce treats that as no-tax.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Drupal Commerce order.
   */
  public function apply(OrderInterface $order) {
    $shipping = $this->extractShippingPostalContext($order);
    if ($shipping === NULL) {
      return;
    }
    [$country, $zip5] = $shipping;
    $currency = $this->extractCurrency($order);

    $lines = $this->extractLineItems($order);
    if ($lines === []) {
      return;
    }

    $response = $this->calculator->calculateForOrder($country, $currency, $zip5, $lines);
    if ($response === NULL) {
      return;
    }

    $this->writeAdjustments($order, $response, $currency);
  }

  /**
   * Extracts (country, zip5) from the order's shipping profile.
   *
   * Uses duck-typed accessors so the plugin survives both
   * commerce_shipping installed and not-installed scenarios. Returns
   * NULL when the order has no shipping profile yet (anonymous cart
   * before address collection).
   *
   * @param object $order
   *   The order.
   *
   * @return array{0: string, 1: string}|null
   *   [country_code, postal_code] or NULL.
   */
  protected function extractShippingPostalContext(object $order): ?array {
    $profile = NULL;
    if (method_exists($order, 'collectProfiles')) {
      $profiles = $order->collectProfiles();
      // Prefer shipping profile, fall back to billing.
      foreach (['shipping', 'billing'] as $profileType) {
        if (is_array($profiles) && isset($profiles[$profileType]) && is_object($profiles[$profileType])) {
          $profile = $profiles[$profileType];
          break;
        }
      }
    }
    if ($profile === NULL && method_exists($order, 'getBillingProfile')) {
      $profile = $order->getBillingProfile();
    }
    if ($profile === NULL || !is_object($profile)) {
      return NULL;
    }

    $address = $this->readAddressField($profile);
    if ($address === NULL) {
      return NULL;
    }

    $country = $this->readStringMethod($address, ['getCountryCode', 'country_code']);
    $postal = $this->readStringMethod($address, ['getPostalCode', 'postal_code']);
    if ($country === '' || $postal === '') {
      return NULL;
    }

    // Strip ZIP+4 if present; engine wants exactly 5 digits.
    if (preg_match('/^(\d{5})(?:-?\d{4})?$/', $postal, $m) === 1) {
      return [$country, $m[1]];
    }
    return [$country, $postal];
  }

  /**
   * Reads the AddressItem from a profile (duck-typed).
   *
   * @param object $profile
   *   The profile entity.
   *
   * @return object|null
   *   The address item (object with getCountryCode/getPostalCode) or NULL.
   */
  protected function readAddressField(object $profile): ?object {
    if (!method_exists($profile, 'get')) {
      return NULL;
    }
    try {
      $field = $profile->get('address');
    }
    catch (\Throwable $e) {
      return NULL;
    }
    if (!is_object($field)) {
      return NULL;
    }
    if (method_exists($field, 'isEmpty') && $field->isEmpty()) {
      return NULL;
    }
    if (method_exists($field, 'first')) {
      $first = $field->first();
      return is_object($first) ? $first : NULL;
    }
    return $field;
  }

  /**
   * Reads a string value from an object via the first method that exists.
   *
   * @param object $obj
   *   Source object.
   * @param array<int, string> $methods
   *   Method or property names to try in order.
   *
   * @return string
   *   The string value, or '' when none matched.
   */
  protected function readStringMethod(object $obj, array $methods): string {
    foreach ($methods as $name) {
      if (method_exists($obj, $name)) {
        $value = $obj->{$name}();
        return is_string($value) ? $value : '';
      }
      if (isset($obj->{$name})) {
        $value = $obj->{$name};
        return is_string($value) ? $value : '';
      }
    }
    return '';
  }

  /**
   * Extracts the order's currency.
   *
   * @param object $order
   *   The order.
   *
   * @return string
   *   ISO 4217 currency code, or '' when not determinable.
   */
  protected function extractCurrency(object $order): string {
    if (method_exists($order, 'getTotalPrice')) {
      $total = $order->getTotalPrice();
      if (is_object($total) && method_exists($total, 'getCurrencyCode')) {
        return (string) $total->getCurrencyCode();
      }
    }
    if (method_exists($order, 'getStore')) {
      $store = $order->getStore();
      if (is_object($store) && method_exists($store, 'getDefaultCurrencyCode')) {
        return (string) $store->getDefaultCurrencyCode();
      }
    }
    return '';
  }

  /**
   * Extracts normalized line items from the order.
   *
   * Each line item exposes a quantity and a unit price; we multiply
   * them into the line total (decimal-string math to preserve cent
   * precision) before sending to the engine.
   *
   * @param object $order
   *   The order.
   *
   * @return array<int, array{amount: string, category: string, line_id: string}>
   *   Normalized line items.
   */
  protected function extractLineItems(object $order): array {
    if (!method_exists($order, 'getItems')) {
      return [];
    }
    $items = $order->getItems();
    if (!is_iterable($items)) {
      return [];
    }
    $out = [];
    foreach ($items as $item) {
      if (!is_object($item)) {
        continue;
      }
      $amount = $this->extractLineAmount($item);
      if ($amount === NULL) {
        continue;
      }
      $lineId = '';
      if (method_exists($item, 'id')) {
        $idValue = $item->id();
        $lineId = $idValue === NULL ? '' : (string) $idValue;
      }
      $out[] = [
        'amount' => $amount,
        'category' => 'general',
        'line_id' => $lineId,
      ];
    }
    return $out;
  }

  /**
   * Extracts a line's taxable amount as a decimal string.
   *
   * Prefers getAdjustedTotalPrice() (post-promo) when available;
   * falls back to getTotalPrice() then unit-price * quantity.
   *
   * @param object $item
   *   The order item.
   *
   * @return string|null
   *   Decimal-string amount, or NULL when not computable.
   */
  protected function extractLineAmount(object $item): ?string {
    if (method_exists($item, 'getAdjustedTotalPrice')) {
      $price = $item->getAdjustedTotalPrice();
      $amount = $this->priceToString($price);
      if ($amount !== NULL) {
        return $amount;
      }
    }
    if (method_exists($item, 'getTotalPrice')) {
      $amount = $this->priceToString($item->getTotalPrice());
      if ($amount !== NULL) {
        return $amount;
      }
    }
    if (method_exists($item, 'getUnitPrice') && method_exists($item, 'getQuantity')) {
      $unit = $this->priceToString($item->getUnitPrice());
      $qty = (string) $item->getQuantity();
      if ($unit !== NULL && preg_match('/^\d+(\.\d+)?$/', $qty) === 1) {
        return number_format((float) $unit * (float) $qty, 2, '.', '');
      }
    }
    return NULL;
  }

  /**
   * Converts a Price value object to a decimal string.
   *
   * @param mixed $price
   *   Anything Drupal Commerce yields as a price-like value.
   *
   * @return string|null
   *   Decimal-string amount, or NULL.
   */
  protected function priceToString(mixed $price): ?string {
    if (!is_object($price)) {
      return NULL;
    }
    if (method_exists($price, 'getNumber')) {
      $number = $price->getNumber();
      if (is_string($number) && $number !== '') {
        return $number;
      }
      if (is_int($number) || is_float($number)) {
        return number_format((float) $number, 2, '.', '');
      }
    }
    return NULL;
  }

  /**
   * Writes per-jurisdiction tax adjustments to the order.
   *
   * Uses duck-typed addAdjustment calls so this code doesn't hard-depend
   * on Drupal Commerce's Adjustment value object class at test time.
   * In production, Drupal Commerce's `Adjustment` is autoloaded; in unit
   * tests, we exercise the math in TaxCalculatorTest and verify the
   * payload shape via the plugin's extractLineItems() test.
   *
   * @param object $order
   *   The order.
   * @param \OpenSalesTax\Responses\CalculateResponse $response
   *   The engine response.
   * @param string $currency
   *   Currency code for the adjustment.
   */
  protected function writeAdjustments(object $order, $response, string $currency): void {
    if (!method_exists($order, 'addAdjustment')) {
      return;
    }
    $adjustmentClass = 'Drupal\\commerce_order\\Adjustment';
    if (!class_exists($adjustmentClass)) {
      return;
    }
    $priceClass = 'Drupal\\commerce_price\\Price';
    if (!class_exists($priceClass)) {
      return;
    }
    foreach ($response->lines as $line) {
      if ($line->tax === '0' || $line->tax === '') {
        continue;
      }
      $jurisdictions = $line->jurisdictions;
      if ($jurisdictions === []) {
        $order->addAdjustment(new $adjustmentClass([
          'type' => 'tax',
          'label' => 'Sales tax',
          'amount' => new $priceClass($line->tax, $currency),
          'source_id' => 'opensalestax',
          'percentage' => $line->ratePct,
        ]));
        continue;
      }
      foreach ($jurisdictions as $jurisdiction) {
        // The SDK's JurisdictionRate exposes (name, type, ratePct, tax) —
        // we synthesise a stable source_id from type + name for adjustment
        // dedup / cancellation correlation. No `code` field exists.
        $sourceKey = strtolower(($jurisdiction->type ?? 'tax') . ':' . ($jurisdiction->name ?? 'unknown'));
        $order->addAdjustment(new $adjustmentClass([
          'type' => 'tax',
          'label' => $this->jurisdictionLabel($jurisdiction),
          'amount' => new $priceClass($jurisdiction->tax, $currency),
          'source_id' => 'opensalestax:' . $sourceKey,
          'percentage' => $jurisdiction->ratePct,
        ]));
      }
    }
  }

  /**
   * Formats a jurisdiction line label.
   *
   * @param object $jurisdiction
   *   A JurisdictionRate from the SDK.
   *
   * @return string
   *   Human-readable label.
   */
  protected function jurisdictionLabel(object $jurisdiction): string {
    $name = property_exists($jurisdiction, 'name') ? (string) $jurisdiction->name : '';
    if ($name !== '') {
      return $name;
    }
    return 'Sales tax';
  }

}
