# Contracts And DTOs

Design the interface around Voitity's needs, not around one provider's API.

## Interface Pattern

Example payment contract:

```php
interface PaymentClient
{
    public function createPayment(PaymentRequest $request): PaymentIntent;

    public function getPayment(string $providerPaymentId): PaymentIntent;

    public function cancelPayment(string $providerPaymentId): PaymentIntent;

    public function refundPayment(PaymentRefundRequest $request): PaymentRefund;

    public function parseWebhook(array $headers, string $payload): PaymentWebhook;
}
```

Example email contract:

```php
interface EmailClient
{
    public function sendEmail(EmailMessage $message): EmailDelivery;

    public function getDelivery(string $providerMessageId): EmailDelivery;
}
```

## DTO Rules

DTO/result objects should expose normalized fields:

- `source` or provider name
- provider id
- normalized status
- request URL when useful
- raw response array
- metadata array
- helpers like `isSuccessful`, `isFailed`, `isPending`
- `toArray()`

Keep provider-specific field names inside the adapter.

## Status Normalization

Use simple internal statuses:

- `pending`
- `processing`
- `completed`
- `failed`
- `cancelled`
- `refunded`
- `expired`

Add service-specific statuses only when the application logic needs them.

## Service Wrapper

The wrapper coordinates domain behavior:

- calls the configured client
- creates or updates local models when needed
- dispatches events when needed
- hides manager/client resolution from controllers

The wrapper should not contain provider payload details.
