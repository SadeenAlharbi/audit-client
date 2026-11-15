# company/audit-client

A thin Laravel SDK for the **Audit Log Hub** REST API. It turns calls like

```php
Audit::log('order.paid', $user, $order, ['status' => 'pending'], ['status' => 'paid']);
```

into signed, masked, idempotent `POST /logs` / `POST /logs/batch` requests, sent from your queue so your application never waits for the platform, or breaks when it is down.

The package contains **no platform logic** and never touches the platform database. Everything it does is a plain call to the public API described in the platform's OpenAPI contract (`docs/openapi.yaml` in the Audit Log Hub repository). Anything this package does can also be done with `curl`.

| | |
|---|---|
| PHP | 8.2, 8.3, 8.4 |
| Laravel | 11, 12, 13 (13 requires PHP ≥ 8.3) |
| API | Audit Log Hub API v1 |

---

## Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [The `Audit` facade](#the-audit-facade)
  - [Fluent builder](#fluent-builder)
  - [Eloquent models: the `Auditable` trait](#eloquent-models-the-auditable-trait)
  - [Authentication events](#authentication-events)
  - [Request id middleware](#request-id-middleware)
  - [Raw API access](#raw-api-access)
- [How delivery works](#how-delivery-works)
- [Outbox and `audit:flush`](#outbox-and-auditflush)
- [Security](#security)
- [Testing your application](#testing-your-application)
- [Without Laravel](#without-laravel)
- [Versioning](#versioning)
- [Developing this package](#developing-this-package)

---

## Installation

The package lives in the private repository [SadeenAlharbi/audit-client](https://github.com/SadeenAlharbi/audit-client). Until it is added to a private Composer repository (see [docs/publishing.md](docs/publishing.md)), point Composer at it directly; your machine or CI needs read access to the repository (SSH key or a GitHub token):

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:SadeenAlharbi/audit-client.git" }
]
```

```bash
composer require company/audit-client:^1.0
php artisan vendor:publish --tag=audit-config        # config/audit-client.php
```

If you keep undeliverable logs in the database (the default outbox driver), publish and run the migration:

```bash
php artisan vendor:publish --tag=audit-migrations    # creates the audit_outbox table
php artisan migrate
```

Add your project's credentials (shown once when the API key is created in the platform) to `.env`:

```dotenv
AUDIT_ENDPOINT=https://audit.example.com/api/v1
AUDIT_PROJECT=shop
AUDIT_API_KEY=alh_...
AUDIT_HMAC_SECRET=...           # leave empty if the key has no signing secret
```

Logs are sent by a queued job, so a queue worker must be running (`php artisan queue:work`). The service provider and the `Audit` alias are auto-discovered.

## Configuration

All keys live in `config/audit-client.php` and can be set through the environment.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `AUDIT_ENABLED` | `true` | `false` sends, queues and buffers nothing. |
| `endpoint` | `AUDIT_ENDPOINT` | — | Base URL **including** `/api/v1`. |
| `project` | `AUDIT_PROJECT` | — | Project slug (`X-Project`). |
| `api_key` | `AUDIT_API_KEY` | — | Project API key (`Authorization: Bearer`). |
| `hmac_secret` | `AUDIT_HMAC_SECRET` | `null` | Signing secret; `null` turns signing off. |
| `timeout` / `connect_timeout` | `AUDIT_TIMEOUT` / `AUDIT_CONNECT_TIMEOUT` | `5` / `2` | Seconds. |
| `queue.connection` | `AUDIT_QUEUE_CONNECTION` | app default | Queue connection of the send job. `sync` sends inline. |
| `queue.name` | `AUDIT_QUEUE` | app default | Queue name, e.g. `audit`. Make sure a worker listens to it. |
| `retry.tries` | `AUDIT_RETRY_TRIES` | `6` | Total delivery attempts per job. |
| `retry.backoff` | — | `[10, 30, 90, 270, 810]` | Seconds before each retry; the last value is reused. |
| `batch.enabled` | `AUDIT_BATCH` | `true` | Buffer logs and send them together. |
| `batch.size` | `AUDIT_BATCH_SIZE` | `100` | Flush when the buffer reaches this size (max 500). |
| `batch.flush_on_terminate` | — | `true` | Flush when a request, command or queued job ends. |
| `mask` | — | see below | Keys whose values are masked before sending. |
| `mask_replacement` | — | `********` | Replacement value. |
| `outbox.driver` | `AUDIT_OUTBOX_DRIVER` | `database` | `database`, `file` or `null`. |
| `outbox.connection` / `outbox.table` | `AUDIT_OUTBOX_CONNECTION` | default / `audit_outbox` | Database outbox location. |
| `outbox.path` | — | `storage/audit-client/outbox.jsonl` | File outbox location. |
| `listen_auth_events` | `AUDIT_LISTEN_AUTH_EVENTS` | `true` | Log `Login`, `Logout`, `Failed`. |
| `resolve_actor` | — | `true` | Use the authenticated user when no actor is given. |

Default mask list: `password`, `password_confirmation`, `token`, `access_token`, `refresh_token`, `secret`, `api_key`, `card_number`, `cvv`, `authorization`.

## Usage

### The `Audit` facade

```php
use Company\AuditClient\Facades\Audit;

Audit::log(
    'user.updated',        // event
    $admin,                // actor: Eloquent model, Authenticatable, array or null
    $user,                 // subject: Eloquent model, array or null
    ['role' => 'editor'],  // old values (or null)
    ['role' => 'admin'],   // new values (or null)
    [                      // options (all optional)
        'action' => 'update',             // default: inferred from the event (see below)
        'severity' => 'warning',          // info (default) | warning | critical
        'metadata' => ['reason' => 'promotion'],
        'tags' => ['users', 'roles'],
        'external_id' => 'user-7-role-42', // default: a new ULID
        'occurred_at' => now(),            // DateTimeInterface or string; default: now
        'correlation_id' => 'checkout-7f3a',
        'request_id' => '...',             // default: the AddRequestId middleware's id
    ],
);
```

`Audit::log()` returns the log's `external_id`, or `null` when auditing is disabled or the log could not be recorded. **It never throws**: invalid input (an unknown option, a bad event name...) is reported to your exception handler and your code carries on.

How arguments become the API payload (`LogInput`):

| Argument | Payload |
|---|---|
| Eloquent model / Authenticatable actor | `actor: {type: "user", id: "42", name: "<name attribute>"}` (type = snake-cased class name) |
| array actor | only `type`, `id`, `name`, `ip`, `user_agent` are kept |
| `null` actor | the authenticated user (if `resolve_actor`), otherwise no actor |
| During an HTTP request | `actor.ip` and `actor.user_agent` are added from the request |
| model / array subject | `subject: {type, id}` |
| old / new / metadata | dates → ISO-8601 UTC, enums → values, models/collections → arrays; then masked |
| `occurred_at` | ISO-8601 UTC with milliseconds: `2026-11-11T10:15:30.120Z` |

Empty optional fields are omitted; the package never sends a field the API does not know. When no action is given it is inferred from the event's last segment: `created`→`create`, `updated`→`update`, `deleted`→`delete`, `restored`→`restore`, `login`, `logout`, `viewed`→`view`, `exported`→`export`, anything else → `custom`. The `Company\AuditClient\Enums\Action` and `Severity` enums can be passed wherever a string is accepted.

### Fluent builder

```php
Audit::event('order.paid')
    ->by($user)                              // actor
    ->on($order)                             // subject
    ->changes(['status' => 'pending'], ['status' => 'paid'])
    ->action('update')
    ->severity('info')
    ->metadata(['channel' => 'web'])
    ->tags('billing', 'orders')
    ->correlationId($checkoutId)
    ->externalId("order-{$order->id}-paid")
    ->send();                                // returns the external_id (or null)
```

### Eloquent models: the `Auditable` trait

```php
use Company\AuditClient\Concerns\Auditable;

class Order extends Model
{
    use Auditable, SoftDeletes;

    protected $hidden = ['internal_token'];       // never sent

    protected array $auditExclude = ['views'];    // optional: also never sent
}
```

| Model event | Event name | action | old_values | new_values |
|---|---|---|---|---|
| created | `order.created` | `create` | — | all attributes |
| updated | `order.updated` | `update` | changed attributes, before | changed attributes, after |
| deleted | `order.deleted` | `delete` | all attributes | — |
| restored (SoftDeletes) | `order.restored` | `restore` | — | all attributes |

- Values are serialized like the model's own `toArray()` (casts, dates), without `$appends`.
- `$hidden` and `$auditExclude` attributes are never included; an update that only changed excluded attributes is not logged. Add `updated_at` to `$auditExclude` if you do not want it in update diffs.
- The mask list is applied on top (e.g. an `api_key` column is sent as `********`).
- The actor is the authenticated user. Override `auditEventName(string $modelEvent)` to rename events.
- Auditing never interrupts a save or delete: failures are reported, not thrown.

### Authentication events

With `listen_auth_events` enabled (default), the package listens to Laravel's auth events:

| Laravel event | Event | action | severity | metadata |
|---|---|---|---|---|
| `Illuminate\Auth\Events\Login` | `auth.login` | `login` | info | `guard`, `remember` |
| `Illuminate\Auth\Events\Logout` | `auth.logout` | `logout` | info | `guard` |
| `Illuminate\Auth\Events\Failed` | `auth.failed` | `failed_login` | warning | `guard`, `credentials` (password masked) |

### Request id middleware

`Company\AuditClient\Http\Middleware\AddRequestId` (alias `audit.request-id`) reads `X-Request-Id` from the incoming request, or creates a UUID when it is missing or invalid, adds it as `request_id` to every log recorded during the request, and returns it in the response's `X-Request-Id` header. The id is stored in Laravel's hidden Context, so jobs queued during the request carry it too.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Company\AuditClient\Http\Middleware\AddRequestId::class);
})

// or per route / group
Route::middleware('audit.request-id')->group(...);
```

### Raw API access

`Audit::client()` returns the signed HTTP client, for API calls the facade does not wrap:

```php
$batch = Audit::client()->send('GET', "batches/{$batchUid}")->json('data');
$logs  = Audit::client()->send('GET', 'logs', ['event' => 'order.*', 'per_page' => 50])->json();
```

## How delivery works

```
Audit::log() ─► mask + normalize ─► buffer ──(full / request ends)──► SendAuditLogs job ─► POST /logs/batch
                                     (or straight to a job when batching is off)           (POST /logs for 1 log)
```

1. The log is validated, normalized and **masked when it is recorded**, so secrets never reach your queue or outbox. It gets an `external_id` (ULID) at that moment, so every retry and re-send reuses it.
2. With batching on, logs are buffered in memory and flushed when the buffer reaches `batch.size`, and when the HTTP request, console command or queued job ends. Each flush is split into jobs of at most 500 logs (the API limit).
3. The `SendAuditLogs` job sends the logs and classifies the answer:

| Response | Result |
|---|---|
| `202` | Delivered. |
| `409 duplicate` | Delivered (the platform already has it). |
| `202` batch item with `status: rejected` | That item goes to the outbox with its errors; the others are delivered. |
| Other `4xx` (`422`, `401`, `403`, `413`, ...) | Permanent: the logs go to the outbox with the error. **Not retried.** |
| Network error, `408`, `429`, `5xx` | Released back onto the queue after `10, 30, 90, 270, 810` s (`Retry-After` wins on `429`). After the last try → outbox. |

With the `sync` queue connection the job runs inline and is not retried; failures go straight to the outbox. If the job cannot even be queued (queue backend down), the logs go to the outbox too.

## Outbox and `audit:flush`

Undeliverable logs are kept, one entry per log, with the number of attempts and the last error:

- `database`: the `audit_outbox` table (`--tag=audit-migrations`).
- `file`: a JSON-lines file (`outbox.path`), safe for concurrent processes (file locks).
- `null`: dropped, with a warning in your application log.

Re-send them with:

```bash
php artisan audit:flush                 # everything, 500 per request
php artisan audit:flush --batch=100     # smaller requests (e.g. after a 413)
php artisan audit:flush --limit=1000    # at most 1000 entries
```

Delivered (or duplicate) entries are deleted. Entries rejected again stay, with `attempts` incremented and the new error, so you can inspect them (`422` usually means the log itself must be fixed, e.g. `occurred_at` older than the platform's retention). The command stops early if the platform is unavailable and exits non-zero whenever an entry failed. Schedule it:

```php
// routes/console.php
Schedule::command('audit:flush')->everyTenMinutes()->withoutOverlapping();
```

## Security

- **Signing.** When `hmac_secret` is set, every request carries `X-Timestamp` (Unix seconds) and `X-Signature = hex(HMAC-SHA256(secret, canonical))` where `canonical` is `{X-Timestamp}\n{METHOD}\n{path?query exactly as sent}\n{raw body}` — exactly as the API contract specifies. The body that is signed is the body that is sent. The platform rejects timestamps more than 5 minutes off, so keep the server clock in sync (NTP).
- **Masking.** Values of the `mask` keys are replaced recursively, case-insensitively, in `old_values`, `new_values` and `metadata` before the log is queued. The platform masks again on its side.
- **Credentials** are only read from config at send time and never written to the queue payload or the outbox.

## Testing your application

Turn the package off in your test suite:

```xml
<!-- phpunit.xml -->
<env name="AUDIT_ENABLED" value="false"/>
```

Or assert on the queued job:

```php
use Company\AuditClient\Jobs\SendAuditLogs;

Queue::fake();
config(['audit-client.batch.enabled' => false]);

$this->post('/orders/1/pay');

Queue::assertPushed(SendAuditLogs::class, fn ($job) => $job->logs[0]['event'] === 'order.paid');
```

Or let it send through `Http::fake()` with `audit-client.queue.connection` set to `sync`.

## Without Laravel

The REST API is the platform's official contract; this package is only a convenience for Laravel. Other systems (any language) send the same requests directly — see the OpenAPI document (`docs/openapi.yaml` in the Audit Log Hub repository). A signed request in shell:

```bash
BODY='{"event":"order.paid","action":"update","occurred_at":"2026-11-11T10:00:00Z","external_id":"order-1001-paid"}'
TS=$(date +%s)
SIG=$(printf '%s\n%s\n%s\n%s' "$TS" POST /api/v1/logs "$BODY" | openssl dgst -sha256 -hmac "$AUDIT_HMAC_SECRET" | sed 's/^.* //')

curl -sS https://audit.example.com/api/v1/logs \
  -H "Authorization: Bearer $AUDIT_API_KEY" -H "X-Project: shop" \
  -H "Content-Type: application/json" -H "X-Timestamp: $TS" -H "X-Signature: $SIG" \
  --data "$BODY"
```

## Versioning

The package follows [Semantic Versioning](https://semver.org/). Its public API is: the `Audit` facade and `AuditManager` methods, `PendingLog`, the `Auditable` trait, the auth listener, the `AddRequestId` middleware, the `audit:flush` command, the config keys and the outbox table.

- **Patch** (1.0.x): bug fixes, no behaviour change for valid usage.
- **Minor** (1.x.0): new features and config keys with backwards-compatible defaults; support for a new Laravel or PHP version.
- **Major** (x.0.0): removed/renamed public API or config, dropped Laravel/PHP versions, or a new major version of the platform API.

Require it as `company/audit-client:^1.0`. Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## Developing this package

```bash
composer install
composer test        # Pest (Orchestra Testbench, Http::fake — no platform needed)
composer lint        # Pint (laravel preset)
composer analyse     # PHPStan level 6 + Larastan
```

CI runs all three on PHP 8.2–8.4 × Laravel 11–13 (`.github/workflows/tests.yml`). Releasing is described in [docs/publishing.md](docs/publishing.md).
