<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\opensalestax_commerce\Exception;

/**
 * Thrown when the engine API URL is empty.
 *
 * The tax-type plugin catches this and skips calculation silently
 * (returning zero-tax) so an unconfigured module is inert rather than
 * fatal.
 */
final class EngineNotConfiguredException extends \RuntimeException {

}
