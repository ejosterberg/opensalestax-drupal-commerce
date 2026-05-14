<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\Tests\opensalestax_commerce\Unit\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\opensalestax_commerce\Form\SettingsForm;
use Drupal\opensalestax_commerce\Service\UrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Drupal\opensalestax_commerce\Form\SettingsForm
 */
final class SettingsFormTest extends TestCase {

  public function testFormIdIsStable(): void {
    $form = new SettingsForm($this->configMock([]), new UrlValidator());
    self::assertSame('opensalestax_commerce_settings', $form->getFormId());
  }

  public function testBuildFormRendersDisclaimerAndFields(): void {
    $form = new SettingsForm(
      $this->configMock(['api_url' => 'https://ost.example.com', 'fail_hard' => TRUE]),
      new UrlValidator(static fn () => '203.0.113.10')
    );
    $built = $form->buildForm([], $this->stateMock([]));
    self::assertArrayHasKey('disclaimer', $built);
    self::assertStringContainsString('as-is', $built['disclaimer']['#markup']);
    self::assertSame('url', $built['api_url']['#type']);
    self::assertSame('https://ost.example.com', $built['api_url']['#default_value']);
    self::assertSame('password', $built['api_key']['#type']);
    // Password field never echoes existing key:
    self::assertSame('', $built['api_key']['#default_value']);
    self::assertTrue($built['fail_hard']['#default_value']);
  }

  public function testValidateAcceptsEmptyUrl(): void {
    $form = new SettingsForm($this->configMock([]), new UrlValidator());
    $state = $this->stateMock(['api_url' => '', 'restrict_to_public_ips' => TRUE]);
    $formArray = [];
    $form->validateForm($formArray, $state);
    self::assertSame([], $state->errors);
  }

  public function testValidateRejectsMalformedUrl(): void {
    $form = new SettingsForm($this->configMock([]), new UrlValidator());
    $state = $this->stateMock(['api_url' => 'not a url', 'restrict_to_public_ips' => FALSE]);
    $formArray = [];
    $form->validateForm($formArray, $state);
    self::assertArrayHasKey('api_url', $state->errors);
  }

  public function testValidateRejectsPrivateIpWhenRestricted(): void {
    $form = new SettingsForm($this->configMock([]), new UrlValidator(static fn () => '10.0.0.5'));
    $state = $this->stateMock(['api_url' => 'http://internal.lan', 'restrict_to_public_ips' => TRUE]);
    $formArray = [];
    $form->validateForm($formArray, $state);
    self::assertArrayHasKey('api_url', $state->errors);
  }

  public function testSubmitDoesNotOverwriteEmptyApiKey(): void {
    $config = $this->configMock(['api_key' => 'existing']);
    $form = new SettingsForm($config, new UrlValidator(static fn () => '203.0.113.10'));
    $state = $this->stateMock([
      'api_url' => 'https://ost.example.com',
      'api_key' => '',
      'restrict_to_public_ips' => FALSE,
      'cache_ttl_seconds' => 86400,
      'fail_hard' => FALSE,
      'timeout_seconds' => 10,
    ]);
    $formArray = [];
    $form->submitForm($formArray, $state);
    self::assertSame('existing', $config->get('opensalestax_commerce.settings')->get('api_key'));
  }

  public function testSubmitWritesNewApiKey(): void {
    $config = $this->configMock(['api_key' => 'existing']);
    $form = new SettingsForm($config, new UrlValidator(static fn () => '203.0.113.10'));
    $state = $this->stateMock([
      'api_url' => 'https://ost.example.com',
      'api_key' => 'new-token',
      'restrict_to_public_ips' => FALSE,
      'cache_ttl_seconds' => 3600,
      'fail_hard' => TRUE,
      'timeout_seconds' => 15,
    ]);
    $formArray = [];
    $form->submitForm($formArray, $state);
    $values = $config->get('opensalestax_commerce.settings');
    self::assertSame('new-token', $values->get('api_key'));
    self::assertSame(3600, $values->get('cache_ttl_seconds'));
    self::assertTrue($values->get('fail_hard'));
  }

  // ---- helpers ----

  /**
   * @param array<string, mixed> $overrides
   */
  private function configMock(array $overrides): ConfigFactoryInterface {
    $defaults = [
      'api_url' => '',
      'api_key' => '',
      'restrict_to_public_ips' => FALSE,
      'cache_ttl_seconds' => 86400,
      'fail_hard' => FALSE,
      'timeout_seconds' => 10,
    ];
    $values = array_merge($defaults, $overrides);
    return new class ($values) implements ConfigFactoryInterface {

      /** @var array<string, mixed> */
      public array $values;
      /**
       * @param array<string, mixed> $values
       */
      public function __construct(array $values) {
        $this->values = $values;
      }

      public function get(string $name): object {
        $self = $this;
        return new class ($self) {

          public function __construct(private object $owner) {}

          public function get(string $key): mixed {
            /** @phpstan-ignore-next-line property.notFound */
            return $this->owner->values[$key] ?? NULL;
          }

          public function set(string $key, mixed $value): self {
            /** @phpstan-ignore-next-line property.notFound */
            $this->owner->values[$key] = $value;
            return $this;
          }

          public function save(): void {
          }

        };
      }

      public function getEditable(string $name): object {
        return $this->get($name);
      }

    };
  }

  /**
   * @param array<string, mixed> $values
   */
  private function stateMock(array $values): FormStateInterface {
    return new class ($values) implements FormStateInterface {

      /** @var array<string, string> */
      public array $errors = [];

      /**
       * @param array<string, mixed> $values
       */
      public function __construct(private array $values) {}

      public function getValue(string $key): mixed {
        return $this->values[$key] ?? NULL;
      }

      public function setErrorByName(string $name, string $message): void {
        $this->errors[$name] = $message;
      }

    };
  }

}
