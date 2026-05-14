<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/**
 * Lightweight Drupal-core stand-ins for unit-test isolation.
 *
 * The real Drupal classes ship with Drupal core and Drupal Commerce
 * (the target site Composer-requires `drupal/core` and
 * `drupal/commerce`). Our tests don't pull those in — they need only
 * narrow interface surfaces. We declare those here so unit tests can
 * exercise our module classes without booting Drupal.
 *
 * This file is loaded by tests/bootstrap.php BEFORE any test runs.
 * It is NOT autoloaded into the runtime module — at install time,
 * Drupal's real classes win.
 */

namespace {
  if (!function_exists('t')) {
    /**
     * Stand-in for Drupal's t() helper.
     *
     * @param string $string
     *   Untranslated text.
     * @param array<string, mixed> $args
     *   Placeholder values.
     *
     * @return string
     *   The string with placeholders substituted.
     */
    function t(string $string, array $args = []): string {
      return strtr($string, $args);
    }
  }
}

namespace Drupal\Core\Cache {

  if (!interface_exists('Drupal\\Core\\Cache\\CacheBackendInterface')) {
    interface CacheBackendInterface {

      public function get(string $cid, bool $allow_invalid = FALSE): false|object;

      public function set(string $cid, mixed $data, int $expire = -1, array $tags = []): void;

    }
  }
}

namespace Drupal\Core\Config {

  if (!interface_exists('Drupal\\Core\\Config\\ConfigFactoryInterface')) {
    interface ConfigFactoryInterface {

      public function get(string $name): object;

      public function getEditable(string $name): object;

    }
  }
}

namespace Drupal\Component\Plugin {

  if (!class_exists('Drupal\\Component\\Plugin\\PluginBase')) {
    class PluginBase {

      /**
       * @var array<string, mixed>
       */
      protected array $configuration;

      /**
       * @var string
       */
      protected string $pluginId;

      /**
       * @var array<string, mixed>|null
       */
      protected ?array $pluginDefinition;

      /**
       * Constructs a plugin.
       *
       * @param array<string, mixed> $configuration
       *   Plugin configuration.
       * @param string $plugin_id
       *   Plugin id.
       * @param array<string, mixed>|null $plugin_definition
       *   Plugin definition.
       */
      public function __construct(array $configuration, string $plugin_id, ?array $plugin_definition) {
        $this->configuration = $configuration;
        $this->pluginId = $plugin_id;
        $this->pluginDefinition = $plugin_definition;
      }

    }
  }
}

namespace Drupal\Core\DependencyInjection {

  if (!interface_exists('Drupal\\Core\\DependencyInjection\\ContainerInjectionInterface')) {
    interface ContainerInjectionInterface {

      public static function create(\Symfony\Component\DependencyInjection\ContainerInterface $container, array $configuration = [], $plugin_id = '', $plugin_definition = NULL);

    }
  }
}

namespace Drupal\Core\Form {

  if (!class_exists('Drupal\\Core\\Form\\ConfigFormBase')) {
    abstract class ConfigFormBase {

      /**
       * @var \Drupal\Core\Config\ConfigFactoryInterface
       */
      protected $configFactory;

      public function __construct(\Drupal\Core\Config\ConfigFactoryInterface $config_factory, $typed_config = NULL) {
        $this->configFactory = $config_factory;
      }

      protected function config(string $name): object {
        return $this->configFactory->get($name);
      }

      /**
       * @phpstan-ignore-next-line
       */
      public function buildForm(array $form, FormStateInterface $form_state): array {
        return $form;
      }

      /**
       * @phpstan-ignore-next-line
       */
      public function validateForm(array &$form, FormStateInterface $form_state): void {
      }

      /**
       * @phpstan-ignore-next-line
       */
      public function submitForm(array &$form, FormStateInterface $form_state): void {
      }

      protected function t(string $string, array $args = []): string {
        return \t($string, $args);
      }

      /**
       * @return array<int, string>
       */
      abstract protected function getEditableConfigNames(): array;

    }
  }

  if (!interface_exists('Drupal\\Core\\Form\\FormStateInterface')) {
    interface FormStateInterface {

      public function getValue(string $key): mixed;

      public function setErrorByName(string $name, string $message): void;

    }
  }
}
