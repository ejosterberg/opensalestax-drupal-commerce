<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/**
 * PHPStan-only Drupal stand-ins.
 *
 * Same shape as tests/stubs/DrupalStubs.php but WITHOUT the
 * `class_exists()` guards — so PHPStan can statically resolve every
 * symbol. This file is referenced from phpstan.neon as a scanFile
 * (not as a path) and is never loaded at runtime.
 *
 * @phpstan-ignore-file
 */

namespace {
  if (!function_exists('t_phpstan_marker_only')) {
    function t_phpstan_marker_only(): void {}
  }
}

namespace Symfony\Component\DependencyInjection {
  interface ContainerInterface {

    public function get(string $id): mixed;

    public function has(string $id): bool;

  }
}

namespace Drupal\Core\Cache {

  interface CacheBackendInterface {

    public function get(string $cid, bool $allow_invalid = FALSE): false|object;

    public function set(string $cid, mixed $data, int $expire = -1, array $tags = []): void;

  }
}

namespace Drupal\Core\Config {

  class ConfigBag {

    /**
     * @return scalar|array|null
     */
    public function get(string $key): string|int|bool|float|array|null { return NULL; }

    public function set(string $key, mixed $value): self { return $this; }

    public function save(): void {}

  }

  interface ConfigFactoryInterface {

    public function get(string $name): ConfigBag;

    public function getEditable(string $name): ConfigBag;

  }
}

namespace Drupal\Component\Plugin {

  class PluginBase {

    /**
     * @var array<string, mixed>
     */
    protected array $configuration;

    protected string $pluginId;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $pluginDefinition;

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $plugin_definition
     */
    public function __construct(array $configuration, string $plugin_id, ?array $plugin_definition) {
      $this->configuration = $configuration;
      $this->pluginId = $plugin_id;
      $this->pluginDefinition = $plugin_definition;
    }

  }
}

namespace Drupal\Core\DependencyInjection {

  interface ContainerInjectionInterface {

    /**
     * @param array<string, mixed> $configuration
     */
    public static function create(\Symfony\Component\DependencyInjection\ContainerInterface $container, array $configuration = [], string $plugin_id = '', mixed $plugin_definition = NULL): self;

  }
}

namespace Drupal\Core\Form {

  abstract class ConfigFormBase {

    /**
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    protected $configFactory;

    public function __construct(\Drupal\Core\Config\ConfigFactoryInterface $config_factory, mixed $typed_config = NULL) {
      $this->configFactory = $config_factory;
    }

    protected function config(string $name): \Drupal\Core\Config\ConfigBag {
      return $this->configFactory->get($name);
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public function buildForm(array $form, FormStateInterface $form_state): array {
      return $form;
    }

    /**
     * @param array<string, mixed> $form
     */
    public function validateForm(array &$form, FormStateInterface $form_state): void {}

    /**
     * @param array<string, mixed> $form
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void {}

    /**
     * @param array<string, mixed> $args
     */
    protected function t(string $string, array $args = []): string {
      return $string;
    }

    /**
     * @return array<int, string>
     */
    abstract protected function getEditableConfigNames(): array;

  }

  interface FormStateInterface {

    public function getValue(string $key): mixed;

    public function setErrorByName(string $name, string $message): void;

  }
}

namespace Drupal\commerce_order {

  class Adjustment {
    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(array $definition) {}
  }
}

namespace Drupal\commerce_price {

  class Price {
    public function __construct(string $number, string $currency) {}
  }
}
