# Campaign Environment Variables & Configuration

## Environment Variables

```env
# ── Batch Processing ──────────────────────────────────────────────
# Number of campaign logs fetched per database query batch
CAMPAIGN_BATCH_SIZE=500

# Safety cap — org-level overrides cannot exceed this
CAMPAIGN_MAX_BATCH_SIZE=1000

# ── Retry Configuration ───────────────────────────────────────────
# Minutes between retry attempts (comma-separated)
CAMPAIGN_RETRY_INTERVALS=5,15,60

# Max retry attempts before marking as permanently_failed
CAMPAIGN_MAX_RETRIES=3

# Log permanently failed messages at warning level
CAMPAIGN_LOG_PERMANENT_FAILURES=true

# ── Chunk Processing ──────────────────────────────────────────────
# Messages processed per ProcessCampaignChunkJob
CAMPAIGN_CHUNK_SIZE=50

# Concurrent HTTP requests per chunk job (controls parallelism)
CAMPAIGN_CONCURRENCY=5

# Milliseconds to pause between each API request (rate limit throttle)
# 0 = no delay, 100 = ~50 msg/sec at concurrency 5
CAMPAIGN_REQUEST_DELAY_MS=100

# Skip Meta API calls, return fake success (for benchmarking only)
CAMPAIGN_LOAD_TEST_MODE=false
```

## Priority Order (highest wins)

1. **Org metadata** — set via `php artisan campaign:config`
2. **Env variable** — set in `.env`
3. **Config default** — hardcoded in `config/campaigns.php`

## Per-Tier Recommendations

Meta rate limits are per phone number:

| Tier | Messages/sec | CONCURRENCY | REQUEST_DELAY_MS | Effective rate |
|------|-------------|-------------|-------------------|----------------|
| Default | 80 MPS | 5 | 100 | ~50 msg/sec |
| Upgraded (100K+ recipients) | 1000 MPS | 10 | 0 | fast |
| Coexistence (Business App + API) | 20 MPS | 3 | 50 | ~20 msg/sec |
| Ultra-conservative | - | 3 | 200 | ~15 msg/sec |

Check your phone number's current throughput:
```
GET https://graph.facebook.com/v18.0/{phone_number_id}?fields=throughput
```

## Quick Speed Reference

The delay controls the send rate. The generator yields one request every `delay` ms:

```
Effective rate = 1000ms / delay
```

| Target speed | Concurrency | Delay | Artisan command |
|-------------|-------------|-------|-----------------|
| 10 msg/sec | 3 | 100 | `--concurrency=3 --delay=100` |
| 20 msg/sec | 3 | 50 | `--concurrency=3 --delay=50` |
| 50 msg/sec | 5 | 100 | `--concurrency=5 --delay=100` |
| 80 msg/sec | 5 | 12 | `--concurrency=5 --delay=12` |
| Fast (no limit) | 10 | 0 | `--concurrency=10 --delay=0` |

**How concurrency and delay work together:**

- **Delay** = milliseconds between yielding the next request to the Guzzle Pool. This is the main rate controller.
- **Concurrency** = how many requests are in-flight at the same time. Does NOT increase the rate — only determines how many are flying at once.
- The rate is `1000 / delay` regardless of concurrency (as long as concurrency >= 1).

**Example:** delay=50ms → 1 request every 50ms → 20 requests per second. Whether concurrency is 1 or 5, the generator still only yields every 50ms.

**Per-org override (recommended):**

Instead of changing the global `.env` (which affects all orgs), use the artisan command for orgs with different limits:

```bash
# Org with 20 msg/sec limit (coexistence mode)
php artisan campaign:config 5 --concurrency=3 --delay=50

# Org with fast sending (upgraded tier)
php artisan campaign:config 12 --concurrency=10 --delay=0

# Verify
php artisan campaign:config 5 --show
```

**Priority:** org metadata override > env variable > config default

---

## Artisan Command: `campaign:config`

Manage per-organization campaign sending settings. Only accessible via server CLI — not exposed in frontend.

### View Settings

```bash
php artisan campaign:config 1 --show
php artisan campaign:config org-uuid-here --show
```

### Set Per-Org Overrides

```bash
# Default tier org (80 MPS limit)
php artisan campaign:config 5 --concurrency=5 --delay=100

# Upgraded tier org (1000 MPS limit) — fast sending
php artisan campaign:config 12 --concurrency=10 --delay=0

# Coexistence mode org (20 MPS limit)
php artisan campaign:config 3 --concurrency=3 --delay=50

# Rate-limited org — conservative
php artisan campaign:config 7 --concurrency=3 --delay=200

# Set chunk size and batch size too
php artisan campaign:config 5 --chunk-size=100 --batch-size=300

# Multiple settings at once
php artisan campaign:config 5 --concurrency=5 --delay=100 --chunk-size=50
```

### Remove Org Overrides (revert to env/defaults)

```bash
php artisan campaign:config 5 --reset
```

### Validation Ranges

| Setting | Min | Max | Unit |
|---------|-----|-----|------|
| chunk_size | 1 | 200 | messages |
| concurrency | 1 | 50 | requests |
| request_delay_ms | 0 | 2000 | milliseconds |
| batch_size | 100 | 1000 | records |

### How It Works

- Settings are stored in the `organizations.metadata` JSON column under `campaigns.*` keys
- Command shows a preview table (current vs new) before applying changes
- Requires confirmation before writing
- Automatically invalidates the org metadata cache so changes take effect immediately on the next campaign run
- Already-running chunk jobs use the values they were dispatched with — new settings apply to newly dispatched jobs

### Example Output

```
$ php artisan campaign:config 1 --show

Campaign settings for: Acme Corp (ID: 1)

┌──────────────────┬──────────────┬─────────────┬───────────┬──────────────┐
│ Setting          │ Org Override │ Env/Default │ Effective │ Source       │
├──────────────────┼──────────────┼─────────────┼───────────┼──────────────┤
│ chunk_size       │ 100          │ 50          │ 100       │ org override │
│ concurrency      │ 5            │ 10          │ 5         │ org override │
│ request_delay_ms │ 100          │ 100         │ 100       │ env/default  │
│ batch_size       │ -            │ 500         │ 500       │ env/default  │
└──────────────────┴──────────────┴─────────────┴───────────┴──────────────┘

Meta rate limits: Default tier=80 msg/s, Upgraded=1000 msg/s, Coexistence=20 msg/s
```