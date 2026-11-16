<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261116180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Razorpay plugin: payment and refund journals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bwp_razorpay_transaction (id INT AUTO_INCREMENT NOT NULL, payment_id INT NOT NULL, receipt VARCHAR(40) NOT NULL, amount INT NOT NULL, currency_code VARCHAR(3) NOT NULL, account_fingerprint VARCHAR(64) NOT NULL, razorpay_order_id VARCHAR(64) DEFAULT NULL, razorpay_payment_id VARCHAR(64) DEFAULT NULL, status VARCHAR(20) NOT NULL, review_reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_38BC05B55399B645 (receipt), UNIQUE INDEX UNIQ_38BC05B575E6BC1D (razorpay_order_id), UNIQUE INDEX UNIQ_38BC05B54C3A3BB (payment_id), INDEX bwp_razorpay_tx_status (status, updated_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE bwp_razorpay_refund (id INT AUTO_INCREMENT NOT NULL, transaction_id INT NOT NULL, refund_payment_id INT NOT NULL, amount INT NOT NULL, currency_code VARCHAR(3) NOT NULL, razorpay_refund_id VARCHAR(64) DEFAULT NULL, status VARCHAR(20) NOT NULL, failure_reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_3D671B49E739D017 (refund_payment_id), UNIQUE INDEX UNIQ_3D671B495364AC43 (razorpay_refund_id), INDEX IDX_3D671B492FC0CB0F (transaction_id), INDEX bwp_razorpay_refund_status (status, updated_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        // No ON DELETE: a Sylius payment that has a Razorpay journal cannot be deleted by accident.
        $this->addSql('ALTER TABLE bwp_razorpay_transaction ADD CONSTRAINT FK_38BC05B54C3A3BB FOREIGN KEY (payment_id) REFERENCES sylius_payment (id)');
        $this->addSql('ALTER TABLE bwp_razorpay_refund ADD CONSTRAINT FK_3D671B492FC0CB0F FOREIGN KEY (transaction_id) REFERENCES bwp_razorpay_transaction (id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Payment journals must be kept for reconciliation.');
    }
}
