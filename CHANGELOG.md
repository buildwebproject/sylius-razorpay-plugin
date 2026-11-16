# Changelog

## 1.0.0-beta.1 (2026-11-16)

First public beta. Tested end-to-end in Sylius 2.3.2 against a Razorpay API mock; the test with a real Razorpay test
account follows before 1.0.0.

- Razorpay Standard Checkout on the Sylius 2 Payment Request API (capture + status).
- Journal with unique receipt: one Razorpay order per payment, reconciliation after timeouts.
- Signature verification plus API read-back before completing the Sylius payment; review status for mismatches.
- Signed webhooks per payment method with secret rotation.
- Refunds through Sylius RefundPlugin, sent after commit with an idempotent receipt.
- Reconcile command `buildwebproject:razorpay:reconcile`.
- Admin settings with payment-option selection; secrets encrypted and never rendered.
