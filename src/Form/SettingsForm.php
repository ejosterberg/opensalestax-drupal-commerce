<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\opensalestax_commerce\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\opensalestax_commerce\Exception\InvalidEngineUrlException;
use Drupal\opensalestax_commerce\Service\UrlValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin configuration form for the OpenSalesTax connector.
 *
 * Route: /admin/commerce/config/opensalestax.
 */
class SettingsForm extends ConfigFormBase {

  public const CONFIG_NAME = 'opensalestax_commerce.settings';

  /**
   * The SSRF-defense URL validator.
   *
   * @var \Drupal\opensalestax_commerce\Service\UrlValidator
   */
  protected UrlValidator $urlValidator;

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\opensalestax_commerce\Service\UrlValidator $url_validator
   *   The URL validator.
   * @param mixed $typed_config_manager
   *   Drupal 10.2+ TypedConfigManager (optional positional argument
   *   accepted for forward compatibility).
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    UrlValidator $url_validator,
    mixed $typed_config_manager = NULL,
  ) {
    if ($typed_config_manager !== NULL && method_exists(ConfigFormBase::class, '__construct')) {
      // Drupal 10.2+: parent takes typed config manager too.
      // @phpstan-ignore-next-line
      parent::__construct($config_factory, $typed_config_manager);
    }
    else {
      parent::__construct($config_factory);
    }
    $this->urlValidator = $url_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $typed_config = NULL;
    if ($container->has('config.typed')) {
      $typed_config = $container->get('config.typed');
    }
    return new static(
      $container->get('config.factory'),
      $container->get('opensalestax_commerce.url_validator'),
      $typed_config,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'opensalestax_commerce_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);
    $apiUrlRaw = $config->get('api_url');
    $apiUrl = is_string($apiUrlRaw) ? $apiUrlRaw : '';

    $form['disclaimer'] = [
      '#markup' => '<div class="messages messages--warning"><strong>'
        . $this->t('Tax calculations are provided as-is for convenience.')
        . '</strong> '
        . $this->t('The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.')
        . '</div>',
    ];

    $form['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Engine API URL'),
      '#description' => $this->t('Base URL of your OpenSalesTax engine, e.g. <code>https://ost.example.com</code>. Leave empty to disable; the module is inert until this is set.'),
      '#default_value' => $apiUrl,
      '#maxlength' => 500,
    ];

    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key (optional)'),
      '#description' => $this->t('Bearer token if your engine requires authentication. Leave blank when the engine accepts unauthenticated calls. The current value is hidden for security; clear the field to keep the existing key, or enter a new one to replace it.'),
      '#default_value' => '',
    ];

    $form['restrict_to_public_ips'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Restrict to public IPs (SSRF defense)'),
      '#description' => $this->t('When enabled, the engine URL is rejected if it resolves to a private, loopback, link-local, CGNAT, or multicast IP. Disable only when the engine is on the same private network as Drupal (e.g. <code>http://10.x.x.x:8080</code>).'),
      '#default_value' => (bool) $config->get('restrict_to_public_ips'),
    ];

    $form['cache_ttl_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#description' => $this->t('How long to cache engine responses per ZIP+line-signature. Default 86400 (24h). Minimum 3600.'),
      '#default_value' => (int) ($config->get('cache_ttl_seconds') ?? 86400),
      '#min' => 3600,
      '#max' => 604800,
    ];

    $form['timeout_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Engine HTTP timeout (seconds)'),
      '#description' => $this->t('Maximum time to wait for the engine to respond before falling back to fail-soft (or fail-hard, see below). Default 10.'),
      '#default_value' => (int) ($config->get('timeout_seconds') ?? 10),
      '#min' => 1,
      '#max' => 60,
    ];

    $form['fail_hard'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fail hard on engine error'),
      '#description' => $this->t('When enabled, a failed engine call blocks checkout. When disabled (default), the engine error is logged and the order proceeds with no tax — the merchant is responsible for resolving the engine outage.'),
      '#default_value' => (bool) $config->get('fail_hard'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $url = (string) $form_state->getValue('api_url');
    $restrict = (bool) $form_state->getValue('restrict_to_public_ips');
    if ($url !== '') {
      try {
        $this->urlValidator->validate($url, $restrict);
      }
      catch (InvalidEngineUrlException $e) {
        $form_state->setErrorByName('api_url', $e->getMessage());
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable(self::CONFIG_NAME);

    $config
      ->set('api_url', (string) $form_state->getValue('api_url'))
      ->set('restrict_to_public_ips', (bool) $form_state->getValue('restrict_to_public_ips'))
      ->set('cache_ttl_seconds', (int) $form_state->getValue('cache_ttl_seconds'))
      ->set('timeout_seconds', (int) $form_state->getValue('timeout_seconds'))
      ->set('fail_hard', (bool) $form_state->getValue('fail_hard'));

    // Only overwrite the API key when the admin actually typed something
    // (a blank password field means "keep the existing key").
    $newKey = (string) $form_state->getValue('api_key');
    if ($newKey !== '') {
      $config->set('api_key', $newKey);
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
