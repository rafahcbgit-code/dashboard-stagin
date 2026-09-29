# Notification scenario review

Reviewed on 2026-09-28. This review covers the existing notification engine, Meta triggers, subscription triggers, admin configuration, and test delivery. It adds no notification types.

## Existing scenarios

| Notification | Source and sending condition | Suppression and identity |
| --- | --- | --- |
| WhatsApp business account disabled or flagged | `account_update`, `DISABLED_UPDATE`, recognized `FLAGGED` or `DISABLE` state | Matching current WABA; real state transition; WABA and transition sequence |
| WhatsApp business account review rejected | `account_review_update`, decision becomes `REJECTED` | Matching current WABA; repeated decision suppressed |
| WhatsApp display name rejected | `phone_number_name_update`, rejected requested name | Matching WABA and display phone; decision belongs to current phone |
| WhatsApp messaging limit changed | `business_capability_update`, known previous and new business limits differ | Equivalent numeric/tier representations suppressed; unknown values preserve last known state |
| WhatsApp phone quality decreased | Existing Graph verification returns a lower known rating | Same current phone, WABA, and credentials; initial/unknown ratings do not count as decreases |
| Template rejected | Webhook or existing Graph synchronization enters `REJECTED` | Current WABA, organization-scoped template, transition sequence |
| Template paused | Webhook or existing Graph synchronization enters `PAUSED` | Same protections as template rejection |
| Template disabled | Webhook or existing Graph synchronization enters `DISABLED` | Same protections as template rejection |
| General template status changed | Existing generic notification definition; no new automatic Meta trigger | Immutable caller-provided source identity and variables |
| Trial expiring soon | Current trial, 3 or 1 local calendar days remaining, after 09:00 local time | Subscription ID, exact expiry, reminder interval; revalidated before resolution and external delivery |
| Subscription expiring soon | Current active paid subscription, 7 or 1 local calendar days remaining, after 09:00 | Same date protections; all active paid subscriptions are eligible |
| Subscription expired | Current active paid subscription's date has passed | Exact current subscription and expiry; launch cutoff; stale queued alerts canceled |
| Trial expired | Current trial's date has passed, without a current active paid replacement | Same expiry protections; a trial with a plan ID remains a trial |
| Resource limit warning | Highest newly crossed 70/80/90/100 percent threshold | Resource account owner, subscription period, effective limit, threshold; unlimited limits excluded |
| Trial started | Audited transition into a current trial with a future end date | Audited source identity; unchanged and superseded transitions ignored |
| Subscription activated | Audited transition into current active paid entitlements | Source identity; local end date; legacy purchase mail remains replaced |
| Plan upgraded | Applied paid plan change increases at least one resource and decreases none | Resource values determine direction; key order and price do not determine direction |
| Plan downgraded | Applied paid plan change decreases at least one resource and increases none | Mixed changes produce neither upgrade nor downgrade alerts |
| Subscription renewed | New paid period, ordinary confirmed paid date renewal, or explicit admin renewal | Source identity; explicit admin extension produces no renewal alert |
| Plan resource limit increased | Current paid resource allowance increases through an add-on or plan allowance edit | Actual resource account owner; current subscription rechecked; add-on organization scoped; durable source identity |

## Confirmed corrections

- Cancel date reminders and expiry alerts that became obsolete while waiting in the queue. Recheck the latest subscription, exact expiry, active/trial classification, and local reminder interval.
- Cancel pending resource warnings after renewal, expiry, suspension, or an effective allowance change. Revalidate the current period and allowance without recounting usage; an extension preserves the period.
- Render trial, activation, and renewal end dates in the organization's effective timezone, consistently with reminders and admin period actions.
- Determine trial allowance eligibility from the stored UTC expiry, independently of the organization selected in an admin's browser session.
- Commit subscription notification projection and its source checkpoint together. Lock the current subscription while projecting. Retry an already recorded source without rebuilding its immutable payload from a renamed organization.
- Keep resource warning deduplication when a previous effective limit returns. Retain completed warning event identities throughout an active subscription period, even when it exceeds the normal retention window; allow pruning after renewal or expiry.
- Ignore unrelated plan metadata edits when deciding whether a resource increase remains applicable. Recheck current subscription status, latest record, and plan before recording each resource owner's alert.
- Cancel unsent email after switching its definition to dashboard-only. Cancel an old webhook destination or signing secret after its configuration changes. Existing rendered message snapshots remain immutable.
- Treat DNS lookup failure and HTTP 408/429/server errors as transient. Respect `Retry-After` seconds or HTTP dates, with a one-day cap. Private DNS addresses and permanent HTTP rejections still fail without contacting an unsafe destination.
- Ignore successful and failed Graph phone responses after the connected phone/account/credentials change. Do not compare quality ratings belonging to different phones.
- Revalidate and lock the current WABA during webhook and Graph template writes. Ignore stale Graph data after reconnection. Reuse the existing shared HTTP client and its testing hook.
- Associate account and display-name comparison state with the actual WABA/phone. Preserve the last valid messaging limit when an unsupported value arrives.
- Update resolver attempt counts and failure state under a row lock. A failed partial resolution rolls back dashboard records and deliveries before retrying.
- A failed database acknowledgement after successful external delivery leaves the processing claim for reconciliation. Accepted email becomes `uncertain` instead of being automatically resent; webhook recovery retains the same receiver idempotency key.

## Delivery rules retained

- Definitions must be enabled; all settings and test delivery require existing platform-admin authorization and validation.
- Managed dashboard and email messages target active organization owners only. Removed, suspended, disabled, or deleted recipients are excluded.
- Resource warnings use dashboard at 70/80 percent and dashboard plus email at 90/100 percent. Optional webhook is independent.
- Business transactions write the outbox; SMTP and webhook I/O occur through the existing queue afterward. No test email creates a production event.
- Unique event/delivery identities, transactional claims, immutable snapshots, bounded retries, and audited sent/failed/canceled/uncertain states remain in place.
- HTML email bodies are sanitized and variable values escaped. Email subjects are normalized to valid plain mail headers.

## Verification and practical limits

Final relevant suite: **169 tests passed, 779 assertions**, in 82.58 seconds.

```sh
php vendor/bin/pest tests/Feature/ManagedNotifications tests/Feature/Meta/WhatsApp --compact
```

Targeted regressions were run during implementation. `git diff --check` passed. Laravel Pint was applied to the changed engine classes without formatting unrelated application code.

Automated tests use an isolated SQLite database and fake SMTP/HTTP transports. Concurrent state changes are injected deterministically; these tests do not reproduce MySQL lock scheduling or send live production notifications. No database migration is required for these corrections.

The scheduler and queue workers must remain running. Failed and uncertain deliveries remain visible for operational review.

SMTP cannot guarantee exactly-once delivery if a provider accepts a message and then the connection fails before acknowledgement. Interrupted email workers remain `uncertain`; webhook receivers should persist the stable idempotency key before applying side effects.

Meta payloads without a reliable event ordering identifier/timestamp cannot prove whether an intervening contradictory event is a late replay. The implementation validates tenant/entity identity and current transitions; it does not invent Meta event fields. Existing comparison data without an entity marker is treated as the baseline for its current connection until the next verified observation.
