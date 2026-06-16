# Folder Structure

Use this structure for a new service family:

```txt
src/app/Classes/PaymentService/
+-- PaymentClient.php
+-- PaymentManager.php
+-- PaymentService.php
+-- PaymentRequest.php
+-- PaymentIntent.php
+-- PaymentRefundRequest.php
+-- PaymentRefund.php
+-- PaymentWebhook.php
+-- Stripe/
    +-- StripePaymentClient.php

src/app/Providers/
+-- PaymentServiceProvider.php

src/config/
+-- payment.php

src/tests/Unit/Classes/PaymentService/
+-- PaymentRequestTest.php
+-- PaymentIntentTest.php
+-- PaymentRefundRequestTest.php
+-- PaymentRefundTest.php
+-- PaymentWebhookTest.php
+-- PaymentManagerTest.php
+-- PaymentServiceTest.php
+-- StripePaymentClientTest.php

src/tests/Unit/Providers/
+-- PaymentServiceProviderTest.php
```

Use this structure when adding a provider to an existing service:

```txt
src/app/Classes/VideoAIService/
+-- VideoAIClient.php
+-- VideoAIManager.php
+-- VideoAIService.php
+-- AiImage.php
+-- AiVideo.php
+-- Runway/
|   +-- RunwayVideoAI.php
+-- Kling/
    +-- KlingVideoAI.php

src/config/videoai.php
src/tests/Unit/Classes/VideoAIService/KlingVideoAITest.php
```

## Naming

Use `{ServiceName}Service` for the folder and wrapper class.
Use `{ServiceName}Client` for the interface.
Use `{ServiceName}Manager` for the Laravel manager.
Use `{Provider}{ServiceName}Client` for provider adapters.

Examples:

- `PaymentService`, `PaymentClient`, `PaymentManager`, `StripePaymentClient`
- `EmailService`, `EmailClient`, `EmailManager`, `SendGridEmailClient`
- `AnalyticsService`, `AnalyticsClient`, `AnalyticsManager`, `SegmentAnalyticsClient`

## Optional Folders

Create these only when needed:

- `Events/` for domain events.
- `Jobs/` for async provider calls or webhook follow-up.
- `Exceptions/` for domain-specific exceptions.
- `Support/` for small provider-independent helpers.

Avoid creating folders before they have a concrete use.
