# Examples

## PaymentService With Stripe

Prompt:

```txt
Implementa una nueva integracion PaymentService con Stripe usando el patron
adaptador del repo.

Capacidades:
- crear payment intent
- consultar payment intent
- cancelar payment intent
- reembolsar payment intent
- validar y parsear webhook de Stripe

Config/env:
- PAYMENT_DRIVER=stripe
- STRIPE_SECRET_KEY
- STRIPE_WEBHOOK_SECRET
- STRIPE_BASE_URL=https://api.stripe.com

Crea PaymentClient, PaymentManager, PaymentService, StripePaymentClient,
PaymentRequest, PaymentIntent, PaymentRefundRequest, PaymentRefund,
PaymentWebhook, config/payment.php, PaymentServiceProvider y tests.

No llames Stripe real en tests. Usa Http::fake. No loguees datos sensibles.
```

Suggested files:

```txt
src/app/Classes/PaymentService/PaymentClient.php
src/app/Classes/PaymentService/PaymentManager.php
src/app/Classes/PaymentService/PaymentService.php
src/app/Classes/PaymentService/Stripe/StripePaymentClient.php
src/app/Classes/PaymentService/PaymentRequest.php
src/app/Classes/PaymentService/PaymentIntent.php
src/app/Classes/PaymentService/PaymentRefundRequest.php
src/app/Classes/PaymentService/PaymentRefund.php
src/app/Classes/PaymentService/PaymentWebhook.php
src/config/payment.php
src/app/Providers/PaymentServiceProvider.php
src/tests/Unit/Classes/PaymentService/PaymentManagerTest.php
src/tests/Unit/Classes/PaymentService/PaymentServiceTest.php
src/tests/Unit/Classes/PaymentService/StripePaymentClientTest.php
src/tests/Unit/Providers/PaymentServiceProviderTest.php
```

## EmailService With SendGrid

Prompt:

```txt
Implementa una nueva integracion EmailService con SendGrid usando el patron
adaptador del repo.

Capacidades:
- enviar email transaccional
- consultar delivery por provider_message_id

Config/env:
- EMAIL_PROVIDER_DRIVER=sendgrid
- SENDGRID_API_KEY
- SENDGRID_BASE_URL=https://api.sendgrid.com
- SENDGRID_DEFAULT_FROM

Crea EmailClient, EmailManager, EmailService, SendGridEmailClient,
EmailMessage, EmailDelivery, config/email_provider.php, ServiceProvider y tests.

No llames SendGrid real en tests. Usa Http::fake.
```

## AnalyticsService With Segment

Prompt:

```txt
Implementa una nueva integracion AnalyticsService con Segment usando el patron
adaptador del repo.

Capacidades:
- track event
- identify user
- group account

Config/env:
- ANALYTICS_DRIVER=segment
- SEGMENT_WRITE_KEY
- SEGMENT_BASE_URL=https://api.segment.io

Crea AnalyticsClient, AnalyticsManager, AnalyticsService, SegmentAnalyticsClient,
DTOs para eventos y resultados, config/analytics.php, ServiceProvider y tests.

No llames Segment real en tests. Usa Http::fake.
```

## Existing Service New Driver

Prompt:

```txt
Agrega un nuevo driver [Provider] para [VideoAIService|VoiceService|ChatAIService]
siguiendo el patron adaptador existente.

Antes de editar revisa la interface Client, Manager, config, ServiceProvider,
driver actual, DTOs y tests.

No cambies la interface salvo que la aplicacion necesite una nueva capacidad.
Mapea respuestas del proveedor a los DTOs existentes y agrega tests con
Http::fake o mocks.
```
