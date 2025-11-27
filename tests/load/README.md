# Webhook Load Testing

This directory contains load testing scripts for validating webhook performance.

## Story Reference

**Story 1.6: Webhook Load Testing and Validation**

### Acceptance Criteria

| AC ID | Criteria | Target |
|-------|----------|--------|
| AC-1.6.1 | 100 webhooks in 60 seconds all return HTTP 200 | p95 < 200ms |
| AC-1.6.2 | All 100 messages processed within 5 seconds | Total < 5s |
| AC-1.6.3 | Zero messages lost or duplicated | 0 loss/duplicates |
| AC-1.6.4 | CPU utilization stays below 80% during burst | CPU < 80% |
| AC-1.6.5 | Media webhooks: media available within 10 seconds | < 10s |

## Scripts

### PHP Script (Cross-platform)

```bash
# Basic test
php tests/load/WebhookLoadTest.php --identifier=your-org-identifier

# Full options
php tests/load/WebhookLoadTest.php \
    --url=http://localhost:8000 \
    --identifier=your-org-identifier \
    --count=100 \
    --duration=60 \
    --mix \
    --verbose
```

### Shell Script (Linux/macOS/CI)

```bash
# Make executable
chmod +x tests/load/webhook-burst.sh

# Basic test
./tests/load/webhook-burst.sh -i your-org-identifier

# Full options
./tests/load/webhook-burst.sh \
    -u http://localhost:8000 \
    -i your-org-identifier \
    -c 100 \
    -d 60 \
    -m \
    -v
```

## Options

| Option | PHP | Shell | Description |
|--------|-----|-------|-------------|
| Base URL | `--url` | `-u` | Application URL (default: http://localhost:8000) |
| Identifier | `--identifier` | `-i` | Organization identifier (required) |
| Count | `--count` | `-c` | Number of webhooks (default: 100) |
| Duration | `--duration` | `-d` | Duration in seconds (default: 60) |
| App Secret | `--app-secret` | `-s` | Signature validation secret |
| Mixed | `--mix` | `-m` | Use mixed payload types |
| Verbose | `--verbose` | `-v` | Detailed output |

## Payload Distribution (Mixed Mode)

When using `--mix` or `-m`:
- Text: 70%
- Image: 15%
- Video: 10%
- Document: 5%

## Output Files

Results are saved in `tests/load/results/`:

- `load_test_YYYY-MM-DD_HHMMSS.json` - Full test results
- `wam_ids_YYYY-MM-DD_HHMMSS.json` - WAM IDs for database verification

## Database Verification

After running load tests, verify message creation:

```php
// In Tinker or via script
$wamIds = json_decode(file_get_contents('tests/load/results/wam_ids_*.json'), true)['wam_ids'];
$createdCount = Chat::whereIn('wam_id', $wamIds)->count();

echo "Expected: " . count($wamIds) . ", Created: " . $createdCount;
```

## Monitoring Scripts

### Queue Depth Monitor (AC-1.6.2, AC-1.6.4)

Monitor queue depth during load tests to verify AC-1.6.2 (processing within 5 seconds):

```bash
# Start monitoring in a separate terminal before running load test
php tests/load/QueueMonitor.php --duration=120 --interval=1
```

Output includes:
- Pending jobs count
- Reserved jobs count
- Failed jobs count
- Processing rate (jobs/second)
- Time when queue clears

### CPU Monitoring (AC-1.6.4)

For CPU monitoring during load tests, use system tools:

**Linux/macOS:**
```bash
# Terminal 1: Start CPU monitoring
top -d 1 | grep -E "^(%Cpu|php)" > cpu_log.txt

# Or use htop for better visualization
htop

# Or use vmstat for detailed stats
vmstat 1 | tee vmstat_output.txt
```

**Windows:**
```powershell
# Use Resource Monitor (resmon.exe)
# Or Performance Monitor (perfmon.exe)
# Or PowerShell:
Get-Counter '\Processor(_Total)\% Processor Time' -Continuous -SampleInterval 1
```

**Docker:**
```bash
docker stats --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}"
```

## Running Load Tests with Monitoring

1. **Terminal 1:** Start queue worker
   ```bash
   php artisan queue:work --queue=webhook-high
   ```

2. **Terminal 2:** Start queue monitor
   ```bash
   php tests/load/QueueMonitor.php --duration=120
   ```

3. **Terminal 3:** Start CPU monitoring (choose appropriate command for your OS)

4. **Terminal 4:** Run load test
   ```bash
   php tests/load/WebhookLoadTest.php --identifier=your-org
   ```

## Prerequisites

### PHP Script
- PHP 7.4+ with curl extension

### Shell Script
- curl
- bc
- openssl (for signature generation)

## Fixtures

JSON payload templates in `tests/load/fixtures/`:
- `text-message.json`
- `image-message.json`
- `video-message.json`
- `document-message.json`
- `audio-message.json`
