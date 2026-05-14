<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Drupal\Tests\opensalestax_commerce\Unit\Service;

use Drupal\opensalestax_commerce\Exception\InvalidEngineUrlException;
use Drupal\opensalestax_commerce\Service\UrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Drupal\opensalestax_commerce\Service\UrlValidator
 */
final class UrlValidatorTest extends TestCase {

  public function testEmptyUrlReturnsNull(): void {
    $validator = new UrlValidator();
    self::assertNull($validator->validate('', TRUE));
    self::assertNull($validator->validate('', FALSE));
  }

  public function testMalformedUrlIsRejected(): void {
    $validator = new UrlValidator();
    $this->expectException(InvalidEngineUrlException::class);
    $validator->validate('not a url', FALSE);
  }

  public function testNonHttpSchemeIsRejected(): void {
    $validator = new UrlValidator();
    $this->expectException(InvalidEngineUrlException::class);
    $this->expectExceptionMessageMatches('/http or https/');
    $validator->validate('ftp://ost.example.com', FALSE);
  }

  public function testFileSchemeIsRejected(): void {
    $validator = new UrlValidator();
    $this->expectException(InvalidEngineUrlException::class);
    $validator->validate('file:///etc/passwd', FALSE);
  }

  public function testValidPublicUrlWithoutRestrictionReturnsNull(): void {
    $validator = new UrlValidator(static fn () => '203.0.113.10');
    self::assertNull($validator->validate('https://ost.example.com', FALSE));
  }

  public function testValidPublicUrlWithRestrictionReturnsResolvedIp(): void {
    $validator = new UrlValidator(static fn () => '203.0.113.10');
    self::assertSame('203.0.113.10', $validator->validate('https://ost.example.com', TRUE));
  }

  public function testIpLiteralIsAccepted(): void {
    $validator = new UrlValidator(static fn (string $host) => $host);
    self::assertSame('203.0.113.10', $validator->validate('https://203.0.113.10', TRUE));
  }

  public function testPrivateRangeIsRejectedWhenRestricted(): void {
    $validator = new UrlValidator(static fn () => '10.0.0.5');
    $this->expectException(InvalidEngineUrlException::class);
    $this->expectExceptionMessageMatches('/public IP/');
    $validator->validate('https://internal.local', TRUE);
  }

  public function testPrivateRangeIsAllowedWhenUnrestricted(): void {
    $validator = new UrlValidator(static fn () => '10.0.0.5');
    self::assertNull($validator->validate('http://10.0.0.5:8080', FALSE));
  }

  public function testLoopbackIsRejectedWhenRestricted(): void {
    $validator = new UrlValidator(static fn () => '127.0.0.1');
    $this->expectException(InvalidEngineUrlException::class);
    $validator->validate('http://localhost:8080', TRUE);
  }

  public function testLinkLocalIsRejectedWhenRestricted(): void {
    $validator = new UrlValidator(static fn () => '169.254.169.254');
    $this->expectException(InvalidEngineUrlException::class);
    // Cloud metadata IP — the canonical SSRF target.
    $validator->validate('http://metadata.aws.internal', TRUE);
  }

  public function testCgnatRangeIsRejectedWhenRestricted(): void {
    $validator = new UrlValidator(static fn () => '100.64.0.1');
    $this->expectException(InvalidEngineUrlException::class);
    $validator->validate('http://cgnat-host.example', TRUE);
  }

  public function testMulticastIsRejectedWhenRestricted(): void {
    $validator = new UrlValidator(static fn () => '224.0.0.1');
    $this->expectException(InvalidEngineUrlException::class);
    $validator->validate('http://224.0.0.1', TRUE);
  }

  public function testUnresolvedHostIsRejected(): void {
    $validator = new UrlValidator(static fn () => NULL);
    $this->expectException(InvalidEngineUrlException::class);
    $this->expectExceptionMessageMatches('/could not be resolved/');
    $validator->validate('https://nx.example.invalid', TRUE);
  }

  public function testMissingHostIsRejected(): void {
    $validator = new UrlValidator();
    $this->expectException(InvalidEngineUrlException::class);
    $validator->validate('https://', FALSE);
  }

  public function testIpv6PrivateRangeIsRejectedWhenRestricted(): void {
    // fc00::/7 unique-local. PHP's filter_var rejects it as private.
    $validator = new UrlValidator(static fn () => 'fc00::1');
    $this->expectException(InvalidEngineUrlException::class);
    $validator->validate('http://[fc00::1]', TRUE);
  }

}
