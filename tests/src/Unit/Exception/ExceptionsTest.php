<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\Tests\opensalestax_commerce\Unit\Exception;

use Drupal\opensalestax_commerce\Exception\EngineNotConfiguredException;
use Drupal\opensalestax_commerce\Exception\InvalidEngineUrlException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Drupal\opensalestax_commerce\Exception\EngineNotConfiguredException
 * @covers \Drupal\opensalestax_commerce\Exception\InvalidEngineUrlException
 */
final class ExceptionsTest extends TestCase {

  public function testEngineNotConfiguredIsRuntimeException(): void {
    $e = new EngineNotConfiguredException('not configured');
    self::assertInstanceOf(\RuntimeException::class, $e);
    self::assertSame('not configured', $e->getMessage());
  }

  public function testInvalidEngineUrlIsInvalidArgumentException(): void {
    $e = new InvalidEngineUrlException('bad url');
    self::assertInstanceOf(\InvalidArgumentException::class, $e);
    self::assertSame('bad url', $e->getMessage());
  }

}
