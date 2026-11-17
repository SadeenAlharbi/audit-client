# Changelog

All notable changes to `company/audit-client` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - 2026-11-17

### Added

- Laravel 11 support again (plan §5: Laravel 11 / 12 / 13 on PHP 8.2+). Laravel 11 has
  unpatched security advisories, so Composer blocks it by default; the CI disables that block
  for the Laravel 11 jobs only, and the host project decides whether to run Laravel 11.

### Changed

- Masking matches keys by their words, exactly like the platform: `password` also masks
  `user_password`, `newPassword`, `password2`, `DBPassword`; `token` masks `remember_token`
  and `APIToken`. Values that are credentials (Bearer/Basic headers, JWTs, Audit Log Hub API
  keys) are masked whatever their key.
- The default mask list is the platform's "never store" list (adds `passwd`, `pwd`,
  `passphrase`, `apikey`, `credential(s)`, `cookie`, `jwt`, `private_key`, `otp`, `pin`,
  `cardnumber`, `credit_card`, `pan`, `cvc`).

### Security

- `auth.failed` no longer sends the submitted credentials. Its metadata is `guard` and `login`
  (the email / username typed), so a password typed into the wrong field never ends up in an
  append-only audit log.

## [1.1.0] - 2026-11-15

### Removed

- Laravel 11 support. Every Laravel 11 release is now affected by unpatched security
  advisories (Laravel 11 is out of security support), so Composer refuses to install it.
  Supported: Laravel 12 and 13 on PHP 8.2-8.4 (Laravel 13 requires PHP 8.3+).

## [1.0.0] - 2026-11-11

### Added

- `Audit` facade: `Audit::log($event, $actor, $subject, $old, $new, $options)` and the fluent
  `Audit::event(...)->by()->on()->changes()->send()` builder, producing the API `LogInput` payload
  exactly (unknown or empty optional fields are never sent).
- Queued delivery (`SendAuditLogs` job) on a configurable connection/queue, with exponential
  backoff (10, 30, 90, 270, 810 s), `Retry-After` support on `429`, and retries on network
  errors, `408` and `5xx`.
- Batching: logs recorded during a request, command or job are sent together through
  `POST /logs/batch` in chunks of at most 500, flushed when the buffer is full and on terminate.
- Idempotency: every log gets a ULID `external_id` when it is recorded; `409 duplicate` and
  duplicate batch items are treated as success.
- Permanent failures (`4xx`, rejected batch items) are written to an outbox without retrying;
  exhausted retries too. Outbox drivers: `database` (publishable `audit_outbox` migration,
  tag `audit-migrations`), `file` (JSON lines) and `null`.
- `audit:flush` command re-sending outbox entries in batches, deleting delivered ones and keeping
  attempts and the last error for the others.
- HMAC-SHA256 request signing (`X-Timestamp`, `X-Signature`) per the API contract.
- Recursive, case-insensitive masking of sensitive keys before logs are queued.
- `Auditable` trait for Eloquent models (created, updated with changed attributes only, deleted,
  restored), honouring `$hidden` and `$auditExclude`.
- Listener for `Login`, `Logout` and `Failed` auth events (`auth.login`, `auth.logout`,
  `auth.failed`).
- `AddRequestId` middleware (alias `audit.request-id`) sharing `X-Request-Id` as `request_id`.
- Publishable config `config/audit-client.php` (tag `audit-config`).
- Support for PHP 8.2–8.4 and Laravel 11, 12 and 13.

[Unreleased]: https://github.com/company/audit-client/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/company/audit-client/releases/tag/v1.0.0
