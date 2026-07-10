# Notifications

This document tracks the notifications that should be supported by the platform.

| Notification | Email | App | Implemented | Observations |
| --- | --- | --- | --- | --- |
| Account email confirmation | Yes | Yes | Partial | Email exists; add in-app pending verification state if not already centralized. |
| Welcome after email verification | Yes | Yes | Partial | Email exists; add in-app success notification after verification redirect. |
| Password recovery link | Yes | No | Yes | Implemented through the password reset email flow. |
| Password changed confirmation | Yes | Yes | Partial | Email exists; add in-app notification for active sessions if needed. |
| Password recovery requested for Google account | Optional | Yes | Partial | API returns provider guidance; optional security email can be added later. |
| Account email changed | Yes | Yes | No | Needed if account email editing is introduced. |
| Password changed from an active session | Yes | Yes | No | Separate from forgot-password flow; should be security-related. |
| Successful plan purchase | Yes | Yes | Partial | Payment/subscription flow exists; confirm whether branded email is sent. |
| Failed payment | Yes | Yes | Partial | Should include reason and retry action when provider supplies enough data. |
| Successful subscription renewal | Yes | Yes | Partial | Relevant for recurring billing jobs. |
| Failed subscription renewal | Yes | Yes | Partial | Should alert user before service is affected. |
| Subscription renewal reminder | Yes | Yes | No | Especially useful for annual plans before the next charge. |
| Admin changed user plan | Yes | Yes | Partial | Admin action exists; notify affected user and optionally admin audit trail. |
| Subscription cancelled or deactivated | Yes | Yes | No | Should confirm effective date and lost limits/features. |
| Critical plan limit reached | Yes | Yes | Partial | Email only when the limit blocks meaningful usage. |
| Monthly usage summary | Recommended | Yes | No | Useful digest for plan value and usage transparency. |
| Critical admin alert | Yes | Yes | No | Use for operational/security issues requiring admin attention. |
| Profile created | No | Yes | Partial | Usually a toast/in-app event is enough. |
| Profile updated | No | Yes | Partial | Use in-app confirmation after save. |
| Profile activated or published | Yes | Yes | Partial | Email confirms that the public profile is now live. |
| Profile deactivated | Yes | Yes | Partial | Email is useful because public availability changed. |
| Profile cannot be activated because requirements are missing | Optional | Yes | Partial | In-app should list missing requirements clearly. |
| Missing avatar required to publish profile | No | Yes | Partial | Show in activation checklist/quality panel. |
| Missing cloned voice required to publish profile | No | Yes | Partial | Show in activation checklist/quality panel. |
| Missing approved and synchronized source required to publish profile | No | Yes | Partial | Show in activation checklist/quality panel. |
| Missing profile alias, name, or description | No | Yes | Partial | Show inline and in quality/activation checks. |
| Profile quality incomplete | No | Yes | Partial | Keep this in profile quality section and global warnings. |
| Source uploaded | No | Yes | Partial | Toast/in-app status after upload. |
| Source processing started | No | Yes | No | Needed for long-running source processing. |
| Source approved | No | Yes | Partial | In-app state should reflect approval. |
| Source synchronized | Optional | Yes | Partial | Email optional if processing takes long. |
| Source rejected or failed | Yes | Yes | No | Email should explain next action if source cannot be used. |
| CV or source data extracted and ready to review | No | Yes | Partial | Should guide the user to review extracted facts. |
| AI-suggested profile changes ready to approve | No | Yes | No | Needed if AI suggestions become an approval workflow. |
| Avatar generation started | No | Yes | Partial | Show progress/status inside avatar section. |
| Avatar generated successfully | Optional | Yes | Partial | Email optional because it can be a long-running task. |
| Avatar generation failed | Yes | Yes | No | Email useful when generation is asynchronous. |
| Avatar activated | No | Yes | Partial | In-app confirmation is enough. |
| Voice cloning started | No | Yes | Partial | Show progress/status inside voice section. |
| Voice cloned successfully | Yes | Yes | Partial | Email useful because cloning may take time. |
| Voice cloning failed | Yes | Yes | No | Email should explain retry requirements. |
| New chat received | No | Yes | No | In-app notification badge/list indicator. |
| New visitor message received | No | Yes | No | Consider email only for configurable owner alerts later. |
| AI response failed | Yes | Yes | No | Email if failure affects public profile responses. |
| Audio response generation failed | Yes | Yes | No | Email if voice/audio is a published profile capability. |
| Plan usage updated | No | Yes | Partial | Show in usage dashboard. |
| Profile limit reached | No | Yes | Partial | Show when attempting to create another profile. |
| Voice limit reached | No | Yes | Partial | Show when attempting voice creation/cloning. |
| Avatar limit reached | No | Yes | Partial | Show when attempting avatar generation. |
| Source limit reached | No | Yes | Partial | Show when attempting source upload. |
| Message or chat limit reached | No | Yes | Partial | Show at the action that is blocked. |
| Payment pending | No | Yes | Partial | In-app state is enough unless pending lasts too long. |
| Payment approved | Yes | Yes | Partial | Email should include plan and amount. |
| Payment rejected | Yes | Yes | Partial | Email should include retry action. |
| Plan activated or changed | Yes | Yes | Partial | Trigger after purchase, renewal, or admin change. |
| Admin impersonation started | No | Yes | Partial | Admin-only audit/notice; avoid emailing end user unless policy requires it. |
| External integration error | Yes | Yes | No | Email for important failures in third-party services. |
| Service maintenance or degradation | Yes | Yes | No | Email for broad impact; app banner for active users. |

## Criteria

- Use email when the user should know even if they are not currently using the app: access, security, billing, important failures, and long-running processes that finished or failed.
- Use in-app notifications for operational states: progress, limits, quality checks, pending actions, and reviewable results.
- `Implemented` uses:
  - `Yes`: implemented end to end.
  - `Partial`: some behavior exists, but it is not yet a complete notification flow.
  - `No`: not implemented.
