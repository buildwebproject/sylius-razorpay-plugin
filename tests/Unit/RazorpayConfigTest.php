<?php

declare(strict_types=1);

namespace Tests\BuildWebProject\SyliusRazorpayPlugin\Unit;

use BuildWebProject\SyliusRazorpayPlugin\Gateway\InvalidConfigException;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RazorpayConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return ['mode' => 'test', 'key_id' => 'rzp_test_ABCDEF123456', 'key_secret' => 'key-secret-0123456789', 'webhook_secret' => 'webhook-secret-0123456789', 'methods' => ['card', 'upi'], 'theme_color' => '#3399cc'];
    }

    public function testValidConfigAndHiddenMethods(): void
    {
        $config = RazorpayConfig::fromArray(self::valid());

        self::assertSame('test', $config->mode);
        self::assertSame([['method' => 'netbanking'], ['method' => 'wallet'], ['method' => 'emi'], ['method' => 'cardless_emi'], ['method' => 'paylater']], $config->hiddenMethods());
    }

    public static function invalid(): iterable
    {
        yield 'live key in test mode' => [['key_id' => 'rzp_live_ABCDEF123456']];
        yield 'unknown mode' => [['mode' => 'sandbox']];
        yield 'short webhook secret' => [['webhook_secret' => 'short']];
        yield 'missing key secret' => [['key_secret' => '']];
        yield 'no methods' => [['methods' => []]];
        yield 'unknown method' => [['methods' => ['card', 'crypto']]];
        yield 'bad colour' => [['theme_color' => 'blue']];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalid')]
    public function testInvalidConfigIsRejected(array $override): void
    {
        $this->expectException(InvalidConfigException::class);
        RazorpayConfig::fromArray(array_replace(self::valid(), $override));
    }

    public function testFingerprintChangesWithKeyOrMode(): void
    {
        $a = RazorpayConfig::fromArray(self::valid());
        $b = RazorpayConfig::fromArray(array_replace(self::valid(), ['key_id' => 'rzp_test_ZZZZZZ999999']));

        self::assertNotSame($a->accountFingerprint(), $b->accountFingerprint());
        self::assertSame($a->accountFingerprint(), RazorpayConfig::fromArray(array_replace(self::valid(), ['key_secret' => 'another-secret-0123']))->accountFingerprint());
    }
}
