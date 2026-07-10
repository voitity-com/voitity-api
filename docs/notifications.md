# Notifications

This document tracks the notifications that should be supported by the platform.

| Notification | Email | App | Implemented | Observations |
| --- | --- | --- | --- | --- |
| Account email confirmation | Yes | Yes | Yes | Email sign-up sends the existing verification email and creates an in-app notification. |
| Welcome after email verification | Yes | Yes | Yes | Email verification sends the existing welcome email and creates an in-app notification. |
| Password recovery link | Yes | No | Yes | Implemented through the password reset email flow. |
| Password changed confirmation | Yes | Yes | Yes | Forgot-password reset sends the existing confirmation email and creates an in-app notification. |
| Password recovery requested for Google account | Optional | Yes | Yes | Password recovery for Google users creates an in-app security notification. |
| Account email changed | Yes | Yes | No | Notification type is configured, but account email editing is not available in the API/admin yet. |
| Password changed from an active session | Yes | Yes | Yes | Settings password change sends the existing confirmation email and creates an in-app notification. |
| Successful plan purchase | Yes | Yes | Yes | Wompi approved purchase webhook creates branded email and in-app notifications. |
| Failed payment | Yes | Yes | Yes | Wompi rejected/failed payments and recurring billing failures notify the user. |
| Successful subscription renewal | Yes | Yes | Yes | Recurring billing and renewal webhooks notify the user. |
| Failed subscription renewal | Yes | Yes | Yes | Recurring billing and renewal webhooks notify the user. |
| Subscription renewal reminder | Yes | Yes | Yes | `notifications:subscription-renewal-reminders` sends reminders for subscriptions due in the configured window. |
| Admin changed user plan | Yes | Yes | Yes | Admin subscription changes notify the affected user. |
| Subscription cancelled or deactivated | Yes | Yes | Yes | Expired active subscriptions are deactivated with email and in-app notification. |
| Critical plan limit reached | Yes | Yes | Yes | Blocking entitlement and usage failures create a critical limit notification. |
| Monthly usage summary | Recommended | Yes | Yes | `notifications:monthly-usage-summary` sends a monthly digest to active subscribers. |
| Critical admin alert | Yes | Yes | Yes | `notifications:admin-alert` sends email and in-app alerts to admins. |
| Profile created | No | Yes | Yes | Profile creation creates an in-app notification. |
| Profile updated | No | Yes | Yes | Profile metadata and data updates create in-app notifications. |
| Profile activated or published | Yes | Yes | Yes | Successful activation sends email and in-app notification. |
| Profile deactivated | Yes | Yes | Yes | Deactivation sends email and in-app notification. |
| Profile cannot be activated because requirements are missing | Optional | Yes | Yes | Failed activation creates a summary in-app notification with missing requirements. |
| Missing avatar required to publish profile | No | Yes | Yes | Failed activation creates a specific in-app notification when avatar is missing. |
| Missing cloned voice required to publish profile | No | Yes | Yes | Failed activation creates a specific in-app notification when cloned voice is missing. |
| Missing approved and synchronized source required to publish profile | No | Yes | Yes | Failed activation creates a specific in-app notification when no approved synchronized source exists. |
| Missing profile alias, name, or description | No | Yes | Yes | Failed activation creates a specific in-app notification when required profile details are missing. |
| Profile quality incomplete | No | Yes | Yes | Failed activation creates an in-app quality warning. |
| Source uploaded | No | Yes | Yes | Successful source import creates an in-app notification. |
| Source processing started | No | Yes | Yes | Successful source import creates an in-app processing notification. |
| Source approved | No | Yes | Yes | Source approval creates an in-app notification. |
| Source synchronized | Optional | Yes | Yes | Source approval/synchronization creates an in-app notification. |
| Source rejected or failed | Yes | Yes | Yes | Source import failures create email and in-app notifications. |
| CV or source data extracted and ready to review | No | Yes | Yes | Source import with extracted items creates an in-app review notification. |
| AI-suggested profile changes ready to approve | No | Yes | Yes | Source import with extracted items creates an in-app AI suggestion notification. |
| Avatar generation started | No | Yes | Yes | Avatar generation requests create an in-app notification. |
| Avatar generated successfully | Optional | Yes | Yes | Async avatar video completion creates an in-app notification. |
| Avatar generation failed | Yes | Yes | Yes | Avatar image/video failures create user notifications and admin integration alerts. |
| Avatar activated | No | Yes | Yes | Manual avatar activation creates an in-app notification. |
| Voice cloning started | No | Yes | Yes | Voice sample processing creates an in-app notification. |
| Voice cloned successfully | Yes | Yes | Yes | Successful voice clone creates email and in-app notification. |
| Voice cloning failed | Yes | Yes | Yes | Voice clone failures create email and in-app notification, plus admin integration alert on final job failure. |
| New chat received | No | Yes | Yes | New profile chat creates an in-app owner notification. |
| New visitor message received | No | Yes | Yes | Visitor messages create an in-app owner notification. |
| AI response failed | Yes | Yes | Yes | Stored message AI processing failures notify the owner and admins. |
| Audio response generation failed | Yes | Yes | Yes | Voice/audio generation failures notify the owner and admins. |
| Plan usage updated | No | Yes | Yes | Usage recorder creates an in-app notification after committed usage. |
| Profile limit reached | No | Yes | Yes | Profile entitlement failures create an in-app notification. |
| Voice limit reached | No | Yes | Yes | Voice entitlement/usage failures create an in-app notification. |
| Avatar limit reached | No | Yes | Yes | Avatar entitlement/usage failures create an in-app notification. |
| Source limit reached | No | Yes | No | Notification type is configured, but subscription plans do not currently define a source-upload quota. |
| Message or chat limit reached | No | Yes | Yes | Chat/message entitlement or usage failures create an in-app notification. |
| Payment pending | No | Yes | Yes | Checkout creation and pending webhook/billing states create in-app notifications. |
| Payment approved | Yes | Yes | Yes | Approved payment webhook/billing states create email and in-app notifications. |
| Payment rejected | Yes | Yes | Yes | Rejected payment webhook/billing states create email and in-app notifications. |
| Plan activated or changed | Yes | Yes | Yes | Purchase, renewal, and admin plan changes create email and in-app notifications. |
| Admin impersonation started | No | Yes | Yes | Admin impersonation creates an in-app admin audit notification. |
| External integration error | Yes | Yes | Yes | Avatar, voice, AI, and audio failure paths notify admins; command exists for manual alerts. |
| Service maintenance or degradation | Yes | Yes | Yes | `notifications:service-notice` sends email and in-app notifications to users with email addresses. |

## Criteria

- Use email when the user should know even if they are not currently using the app: access, security, billing, important failures, and long-running processes that finished or failed.
- Use in-app notifications for operational states: progress, limits, quality checks, pending actions, and reviewable results.
- `Implemented` uses:
  - `Yes`: implemented end to end.
  - `Partial`: some behavior exists, but it is not yet a complete notification flow.
  - `No`: not implemented.
