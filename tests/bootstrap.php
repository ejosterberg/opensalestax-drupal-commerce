<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/**
 * Test bootstrap for opensalestax_commerce.
 *
 * Loads Composer's autoloader, then maps Drupal core classes/interfaces
 * that our unit tests need to lightweight stand-ins. This lets the unit
 * test suite run without a full Drupal install — the real Drupal classes
 * are available on the target site at install time.
 *
 * Kernel/Functional tests run inside a real Drupal install (handled by
 * the target VM) and use Drupal's real classes; they are NOT executed by
 * the unit suite.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/stubs/DrupalStubs.php';
