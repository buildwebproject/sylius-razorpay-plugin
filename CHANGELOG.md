# Changelog

## 1.0.0-rc.1 (2026-11-17)

Release candidate, checked against a real Razorpay test account (no code changes since beta.1).

- Verified with real test keys: API authentication, Razorpay order creation in INR and USD, the live Razorpay
  Checkout window opening from the Sylius payment page with name, email and phone prefilled.
- Verified: tampered signatures and payments of another order are refused (HTTP 409); unsigned or badly signed
  webhooks are refused (HTTP 400); a correctly signed webhook for an unknown payment is accepted and ignored.
- Verified: Key Secret and webhook secret are never rendered in the admin form.
- Screenshots now show the real Razorpay test checkout.
- Docs: install with `^1.0@RC`; note that Razorpay only accepts realistic phone numbers for prefill.

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
