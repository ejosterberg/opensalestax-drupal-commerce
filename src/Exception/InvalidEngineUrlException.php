<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\opensalestax_commerce\Exception;

/**
 * Thrown when an engine URL fails validation.
 *
 * This is a configuration-time problem: the form-level validator catches
 * it and surfaces a user-facing error. It should never reach the
 * checkout path.
 */
final class InvalidEngineUrlException extends \InvalidArgumentException {

}
