<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Form\Type;

use BuildWebProject\SyliusRazorpayPlugin\Gateway\InvalidConfigException;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/** Settings of a Razorpay payment method (Admin → Configuration → Payment methods → Create → Razorpay). */
final class RazorpayGatewayConfigurationType extends AbstractType
{
    private const SECRETS = ['key_secret', 'webhook_secret'];

    private const P = 'buildwebproject_sylius_razorpay.form.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mode', ChoiceType::class, [
                'label' => self::P . 'mode',
                'choices' => [self::P . 'mode_test' => RazorpayConfig::MODE_TEST, self::P . 'mode_live' => RazorpayConfig::MODE_LIVE],
            ])
            ->add('key_id', TextType::class, ['label' => self::P . 'key_id', 'help' => self::P . 'key_id_help', 'attr' => ['autocomplete' => 'off']])
            ->add('key_secret', PasswordType::class, [
                'label' => self::P . 'key_secret',
                'required' => false,
                'always_empty' => false,
                'help' => self::P . 'secret_help',
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('webhook_secret', PasswordType::class, [
                'label' => self::P . 'webhook_secret',
                'required' => false,
                'always_empty' => false,
                'help' => self::P . 'webhook_secret_help',
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('methods', ChoiceType::class, [
                'label' => self::P . 'methods',
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine(array_map(static fn (string $m): string => self::P . 'method_' . $m, RazorpayConfig::METHODS), RazorpayConfig::METHODS),
                'help' => self::P . 'methods_help',
            ])
            ->add('merchant_name', TextType::class, ['label' => self::P . 'merchant_name', 'required' => false, 'help' => self::P . 'merchant_name_help'])
            ->add('theme_color', ColorType::class, ['label' => self::P . 'theme_color', 'required' => false, 'empty_data' => '#3399cc']);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data) || $data === []) {
                $event->setData(['mode' => RazorpayConfig::MODE_TEST, 'methods' => ['card', 'upi', 'netbanking', 'wallet'], 'theme_color' => '#3399cc']);
            }
        });

        // The admin form is a live component that re-renders on every change: only what was typed in this request is
        // echoed back, never the saved secret, which is merged into the model after submit.
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            $saved = $event->getForm()->getData();
            if (!\is_array($data)) {
                return;
            }
            $saved = \is_array($saved) ? $saved : [];
            foreach (['key_id', 'merchant_name'] as $field) {
                $data[$field] = \is_string($data[$field] ?? null) ? trim($data[$field]) : '';
            }
            foreach (self::SECRETS as $field) {
                $typed = \is_string($data[$field] ?? null) ? trim($data[$field]) : '';
                $data[$field] = $typed !== '' ? $typed : (\is_string($saved[$field] ?? null) ? $saved[$field] : '');
            }
            // A new webhook secret keeps the old one valid for retries of events signed before the change.
            $data['previous_webhook_secret'] = ($saved['webhook_secret'] ?? '') !== '' && $data['webhook_secret'] !== $saved['webhook_secret']
                ? (string) $saved['webhook_secret']
                : (string) ($saved['previous_webhook_secret'] ?? '');

            try {
                RazorpayConfig::fromArray($data);
            } catch (InvalidConfigException $exception) {
                $event->getForm()->addError(new FormError($exception->getMessage()));
            }

            $event->setData($data);
        });
    }
}
