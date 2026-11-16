<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Gateway;

use Sylius\Component\Payment\Model\PaymentMethodInterface;

/** Validated settings of one Razorpay payment method (stored encrypted in its Sylius gateway config). */
final readonly class RazorpayConfig
{
    public const FACTORY = 'razorpay';

    public const MODE_TEST = 'test';

    public const MODE_LIVE = 'live';

    /** Payment method groups Razorpay Standard Checkout can show or hide. */
    public const METHODS = ['card', 'upi', 'netbanking', 'wallet', 'emi', 'cardless_emi', 'paylater'];

    /**
     * @param list<string> $methods
     */
    public function __construct(
        public string $mode,
        public string $keyId,
        #[\SensitiveParameter] public string $keySecret,
        #[\SensitiveParameter] public string $webhookSecret,
        #[\SensitiveParameter] public string $previousWebhookSecret,
        public array $methods,
        public string $merchantName,
        public string $themeColor,
    ) {
        $problems = [];
        if (!\in_array($mode, [self::MODE_TEST, self::MODE_LIVE], true)) {
            $problems[] = 'choose Test or Live mode';
        }
        if (preg_match('/^rzp_' . preg_quote($mode, '/') . '_[A-Za-z0-9]{6,40}$/D', $keyId) !== 1) {
            $problems[] = sprintf('the Key ID must start with rzp_%s_ for %s mode', $mode, $mode);
        }
        if (\strlen($keySecret) < 10) {
            $problems[] = 'enter the Key Secret';
        }
        if (\strlen($webhookSecret) < 16) {
            $problems[] = 'enter a webhook secret of at least 16 characters (the same one you set on the Razorpay webhook)';
        }
        if ($methods === [] || array_diff($methods, self::METHODS) !== []) {
            $problems[] = 'choose at least one payment method';
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/D', $themeColor) !== 1) {
            $problems[] = 'the theme colour must look like #3399cc';
        }
        if ($problems !== []) {
            throw new InvalidConfigException('Razorpay settings are incomplete: ' . implode('; ', $problems) . '.');
        }
    }

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        $text = static fn (string $key, string $default = ''): string => \is_string($config[$key] ?? null) ? trim($config[$key]) : $default;
        $methods = \is_array($config['methods'] ?? null) ? array_values(array_filter($config['methods'], 'is_string')) : ['card', 'upi'];

        return new self(
            $text('mode'),
            $text('key_id'),
            $text('key_secret'),
            $text('webhook_secret'),
            $text('previous_webhook_secret'),
            $methods,
            $text('merchant_name'),
            $text('theme_color', '#3399cc') ?: '#3399cc',
        );
    }

    public static function fromPaymentMethod(?PaymentMethodInterface $method): self
    {
        $gatewayConfig = $method?->getGatewayConfig();
        if ($gatewayConfig === null || $gatewayConfig->getFactoryName() !== self::FACTORY) {
            throw new InvalidConfigException('This is not a Razorpay payment method.');
        }

        return self::fromArray($gatewayConfig->getConfig());
    }

    public static function supports(?PaymentMethodInterface $method): bool
    {
        return $method?->getGatewayConfig()?->getFactoryName() === self::FACTORY;
    }

    /** Fingerprint of the merchant account and mode, to detect a changed key on a method with payment history. */
    public function accountFingerprint(): string
    {
        return hash('sha256', $this->mode . '|' . $this->keyId);
    }

    /** @return list<array{method: string}> Razorpay "config.display.hide" blocks for the methods that are switched off */
    public function hiddenMethods(): array
    {
        return array_map(static fn (string $method): array => ['method' => $method], array_values(array_diff(self::METHODS, $this->methods)));
    }
}
