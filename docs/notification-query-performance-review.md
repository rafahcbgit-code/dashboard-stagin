# Notification query performance review — 2026-09-28

## Scope

Reviewed managed notification configuration, sender selection, owner inbox and popup,
event resolution, queued delivery, reconciliation, retention, subscription date
scanning, resource scanning and plan-limit notifications. Meta notification processors
retain their existing tenant checks, primary-key locks and state-transition keys.

## Changes

- Replaced the inbox type-first ordering index with an index beginning with user,
  organization and chronological order. Added an unread popup ordering index. Kept
  the existing unread-count index. Payloads are still fetched only for the selected page.
- Added a shared Laravel subscription scope that seeks the latest row of each
  organization using the existing organization/id index. Latest means latest across
  all statuses and plans, before applying eligibility filters.
- The usage scanner selects only the latest subscription per resource account in SQL,
  instead of hydrating the entire subscription history and discarding it in PHP.
- Processed expiries are excluded before loading organizations and taking row locks.
  The transaction still rechecks the current period and processed marker.
- Recorded reminder identities are prefetched per 200-row batch using exact tenant,
  type and source-key predicates. Existing reminders require no new writes or per-row
  locks. The recorder's unique identity and transactional checks remain authoritative.
- Shared resource accounts are identified using the same mapping as usage summaries.
  Each account is aggregated and checked once per scan, even when its children span
  several subscription batches. A stale period sample is still rejected under lock.
- Resource states are read under the account's subscription transaction. Existing
  unchanged states are neither inserted again nor updated. Removed per-resource
  nested transactions; an error still rolls back all states and events for that account.
- Delivery reuses the event already validated during its claim, avoiding a second
  event read. Resolution checks organization existence without loading its metadata.
- Reconciliation conditionally leases pending work before dispatch. Overlapping
  reconcilers cannot both lease the same eligible row. Dispatch failure releases its
  lease; interrupted dispatch remains recoverable after the existing five-minute timeout.
- Retention uses Laravel `chunkById(500)` so later batches progress beyond earlier
  retained records. Failure and unread retention rules are unchanged.
- Subscription-period auditing uses constructor injection. Usage context remains
  fresh per administrative operation because that service caches subscription/pool data.

No new channel interfaces, repositories or delivery architecture were introduced.

## Local SQL measurements

Run `php scripts/benchmark-notification-queries.php` against a loopback MySQL/MariaDB
connection. The script uses connection-local temporary tables and never writes
application records. Tables disappear when the connection closes.

Environment: local MariaDB 10.4.32. Synthetic data: 100,000 rows in each of three
tables (300,000 total). The inbox includes 50,000 records for one owner across 20
types. Subscriptions include 100 historical rows for each of 1,000 organizations.
Each reported time is the median of three executions after one warm-up, including
PDO fetching. Measurements are affected by local machine load and optimizer choices.

| Query | Before (ms) | After (ms) | Returned rows |
| --- | ---: | ---: | --- |
| Owner inbox, latest page | 124.435 | 3.318 | 20 in both cases |
| Owner's oldest unread managed popup | 4.245 | 0.866 | 1 in both cases |
| Subscriptions for 100 resource accounts | 32.525 | 2.375 | 10,000 before; 100 latest rows after |

`EXPLAIN` showed filesort for the original inbox/popup indexes; the selected new
ordering indexes removed that sort. The latest-account query used the existing
organization index for grouped maximum IDs and primary-key access to the selected rows.

The initial reminder-prefetch candidate using three independent `IN` lists scanned
the full identity index (100,096 estimated rows, 221.624 ms). Exact identity predicates
used a range over 400 estimated rows (11.767 ms), returning the same 400 keys. This is
a comparison between candidate implementations, not a timing of the previous recorder loop.

An expiry filter alone was rejected: it measured 1,757.236 ms with the historical
anti-existence lookup. Combining the filter with the latest-row seek measured
101.704 ms, selecting the 100 unprocessed current expiries. The original first
batch measured 332.251 ms and returned 200 current expiries, including processed
ones; these differ in useful work and should not be treated as equivalent outputs.

## Verification

Final command:

```sh
php vendor/bin/pest tests/Feature/ManagedNotifications tests/Feature/Meta/WhatsApp --compact
```

Result: **108 tests passed, 589 assertions**. These cover the notification engine,
configuration and HTML delivery, Meta transitions, subscription periods, tenant
isolation, deduplication, disabled definitions, retries, uncertain delivery and retention.

Additional regression coverage verifies:

- 210 existing reminders checked across two batches with at most 12 SQL statements
  and no inserts or updates, including after organization names change.
- Processed expiries cause no organization reads or writes, while a later expiry
  is still detected even when changed outside Eloquent without clearing its marker.
- A newer suspended subscription prevents alerts from its older active record.
- Unchanged resource states cause no writes or per-resource savepoints.
- A failure in the second resource rolls back the first resource's state and event.
- One usage aggregation for a shared account with 210 children spanning three batches.
- One event read during delivery claim/send.
- Overlapping reconcilers dispatch each eligible event/delivery once, and a failed
  dispatch can retry immediately after its lease is released.
- Retention deletes 1,001 eligible records across batches while keeping failures/unread data.
- The inbox index migration is reversible and idempotent without deleting notifications.

## Migration and practical limits

New migration: `2026_09_28_000003_optimize_notification_inbox_indexes.php`.
It was applied successfully to the local MariaDB connection. MySQL/MariaDB uses
`ALGORITHM=INPLACE, LOCK=NONE`; SQLite behavior and rollback were tested. Apply this
migration during normal deployment and restart existing queue workers to load code.

These results are not production latency guarantees or an end-to-end load test.
Validate query plans and timings on production-like data and hardware. Inbox totals
still count matching rows; very large unread sets are not constant-time. A very large
shared pool still needs one complete usage aggregation. Failed/uncertain deliveries,
unread notifications and subscription archives retain their existing audit policy
and require storage monitoring. No retention policy was weakened to improve timings.
