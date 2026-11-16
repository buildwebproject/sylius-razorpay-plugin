<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Command;

use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayException;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\InvalidConfigException;
use BuildWebProject\SyliusRazorpayPlugin\Payment\PaymentProcessor;
use BuildWebProject\SyliusRazorpayPlugin\Refund\RefundProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'buildwebproject:razorpay:reconcile',
    description: 'Checks unfinished Razorpay payments and refunds against the Razorpay API (run from cron every 10 minutes).',
)]
final class ReconcileCommand extends Command
{
    public function __construct(
        private readonly PaymentProcessor $processor,
        private readonly ?RefundProcessor $refundProcessor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Only records untouched for this many minutes', '2')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Only payments started within this many days', '7');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $notAfter = new \DateTimeImmutable(sprintf('-%d minutes', max(0, (int) $input->getOption('older-than'))));
        $notBefore = new \DateTimeImmutable(sprintf('-%d days', max(1, (int) $input->getOption('days'))));

        $checked = $failed = 0;
        foreach ($this->processor->findUnfinished($notAfter, $notBefore) as $transaction) {
            ++$checked;
            try {
                $this->processor->synchronize($transaction);
            } catch (RazorpayException|InvalidConfigException $exception) {
                ++$failed;
                $io->warning(sprintf('Payment %s: %s', $transaction->getPayment()->getId(), $exception->getMessage()));
            }
        }

        $refunds = $this->refundProcessor?->processUnfinished($notAfter) ?? 0;
        $io->success(sprintf('Checked %d payment(s) (%d failed) and %d refund(s).', $checked, $failed, $refunds));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
