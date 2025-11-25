# Chatberry Dashboard - Test Suite

## Overview

This test suite uses **Pest PHP v2** for testing the Chatberry Dashboard Laravel application. Tests are focused on validating the Performance Optimization requirements defined in [docs/prd.md](../docs/prd.md).

## Quick Start

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/Http/WebhookControllerTest.php

# Run with verbose output
php artisan test --verbose

# Run with coverage report
php artisan test --coverage
```

## Test Database

Tests use a dedicated MySQL database: `chatberry_dashboard_test`

**Configuration:** [.env.testing](../.env.testing)

```env
DB_DATABASE=chatberry_dashboard_test
```

**Setup:**
```bash
# Database is already created, run migrations
php artisan migrate --env=testing
```

## Directory Structure

```
tests/
├── Feature/                    # Integration/Feature tests
│   ├── Http/                   # Controller/API tests
│   │   └── WebhookControllerTest.php
│   ├── Services/               # Service layer tests
│   ├── Jobs/                   # Queue job tests
│   ├── Models/                 # Model/Query tests
│   │   └── ContactQueryTest.php
│   ├── Events/                 # Event listener tests
│   └── ExampleTest.php         # Basic setup verification
├── Unit/                       # Unit tests
│   └── ExampleTest.php
├── load/                       # k6 load test scripts (future)
├── Pest.php                    # Pest configuration & helpers
├── TestCase.php                # Base test case
└── README.md                   # This file
```

## Test Helpers

Custom helper functions are defined in `tests/Pest.php`:

### `measureTime(callable $callback): float`
Measures execution time in milliseconds.

```php
$duration = measureTime(function () {
    $this->get('/api/contacts');
});

expect($duration)->toBeLessThan(500); // < 500ms
```

### `createTestOrganization(array $attributes = []): Organization`
Creates a test organization with WhatsApp configuration.

```php
$org = createTestOrganization([
    'name' => 'Test Org',
]);
```

### `countQueries(callable $callback): int`
Counts database queries executed in a closure.

```php
$count = countQueries(function () {
    Contact::contactsWithChats($orgId)->get();
});

expect($count)->toBeLessThan(50);
```

## Custom Expectations

### `toRespondWithin(int $milliseconds)`
Assert response time is under threshold.

```php
expect($duration)->toRespondWithin(200); // < 200ms
```

## Test Categories

### Performance Tests (NFR1-NFR8)

| Test | Target | Status |
|------|--------|--------|
| Webhook response time | < 200ms | Pending |
| Contact list load | < 500ms | Pending |
| Query count reduction | 60% | Pending |
| Campaign CPU | < 80% | Pending |

### Functional Tests (FR1-FR50)

Tests map to requirements in [docs/prd.md](../docs/prd.md):

- **FR1-FR8**: Campaign Processing
- **FR9-FR20**: Webhook Processing
- **FR21-FR30**: Chat Module
- **FR31-FR37**: Queue System
- **FR38-FR50**: Monitoring

## Writing Tests

### Pest Syntax

```php
// Basic test
it('returns 200 for valid request', function () {
    $response = $this->get('/api/health');
    $response->assertStatus(200);
});

// Grouped tests
describe('Webhook Processing', function () {
    it('validates signature', function () {
        // ...
    });

    it('queues processing job', function () {
        Queue::fake();
        // ...
        Queue::assertPushed(ProcessWebhookJob::class);
    });
});

// Skipped tests (pending implementation)
it('processes media asynchronously', function () {
    // ...
})->skip('DownloadWebhookMediaJob not yet implemented');
```

### Naming Conventions

- Files: `{Component}Test.php` (e.g., `WebhookControllerTest.php`)
- Tests: Use `it()` with descriptive present tense
- Groups: Use `describe()` for related tests

## Running Specific Tests

```bash
# By file
php artisan test tests/Feature/Http/WebhookControllerTest.php

# By filter (test name)
php artisan test --filter="webhook"

# By group
php artisan test --group=performance

# Only Unit tests
php artisan test --testsuite=Unit

# Only Feature tests
php artisan test --testsuite=Feature
```

## CI/CD Integration

```yaml
# GitHub Actions example
- name: Run Tests
  run: |
    php artisan config:clear
    php artisan test --env=testing
```

## Related Documentation

- [Test Design Document](../docs/test-design-system.md) - Test strategy and testability assessment
- [PRD](../docs/prd.md) - Functional requirements
- [Architecture](../docs/architecture-performance-optimization.md) - Technical architecture

## Troubleshooting

### Tests fail with database connection error

```bash
# Ensure test database exists
php -r "new PDO('mysql:host=localhost', 'root', 'mysql')->exec('CREATE DATABASE IF NOT EXISTS chatberry_dashboard_test');"

# Run migrations
php artisan migrate --env=testing
```

### Tests are slow

```bash
# Use parallel testing
php artisan test --parallel

# Skip coverage for faster runs
php artisan test --no-coverage
```

### RefreshDatabase not working

Ensure `.env.testing` has correct database configuration:
```env
DB_DATABASE=chatberry_dashboard_test
```

---

**Framework:** Pest PHP v2.36.0
**Laravel:** v10
**Last Updated:** 2025-11-25
