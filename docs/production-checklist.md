# Production Security & Deployment Checklist

This document details mandatory production security and operational requirements for the `edutech` application.

---

## 1. Environment & Database Configuration
- [ ] **`APP_DEBUG=false`**: Ensure debug mode is disabled in production to prevent stack traces, configuration leaks, and environment variable exposure.
- [ ] **`LOG_LEVEL=warning`** (or higher): Set logging level to `warning` or `error` in production to avoid cluttering disk and leaking routine operational data.
- [ ] **Dedicated Database User**: Never use the `root` MySQL user in production. Create a dedicated database user with least-privilege permissions granted strictly on the production database.
- [ ] **Audit Log Table Privileges**: In production, revoke `UPDATE` and `DELETE` privileges on the `activity_log` table from the application database user. The audit log is strictly append-only.
- [ ] **HTTPS Enforcement**: Ensure all traffic is forced over HTTPS and TLS 1.3/1.2 (`URL::forceScheme('https')` in production, supported by reverse proxy HSTS).
- [ ] **Trusted Proxies**: Configure `Request::setTrustedProxies()` / Laravel trusted proxies when deployed behind load balancers, Cloudflare, or AWS ALB so that rate-limiting and audit IP logs record the authentic client IP rather than the proxy/balancer IP.

---

## 2. Encryption Keys & Session Security
- [ ] **`APP_KEY` Backup & Rotation**:
  - Securely backup the production `APP_KEY` in password manager / vault.
  - All Two-Factor Authentication (2FA) recovery codes and secrets are encrypted with `APP_KEY`. Losing `APP_KEY` permanently locks out every 2FA user.
  - When rotating the key, use Laravel's `APP_PREVIOUS_KEYS` setting (comma-separated past keys) to re-encrypt existing secrets without breaking user logins. Never simply replace `APP_KEY` in place.
- [ ] **`SESSION_ENCRYPT=true`**: Ensure sessions stored in the database are encrypted at rest.
- [ ] **`SESSION_SECURE_COOKIE=true`**: Ensure cookies are flagged `Secure` and `HttpOnly`.

---

## 3. Multi-Tenancy & Authorization Rules
- [ ] **Strict Model Protection**: `school_id` must never be in `$fillable` on any school-owned model.
- [ ] **Immutable `school_id`**: Once created, `school_id` cannot be changed (enforced by `App\Models\Concerns\BelongsToSchool`).
- [ ] **Role-Based Authorization Only**: Never assign permissions directly to users via `model_has_permissions`. Assign permissions exclusively through roles.
- [ ] **No Platform Admin Bypass**: Never implement a global "platform admin passes every check" gate shortcut. Platform admins must not silently bypass tenant boundaries or educational record privacy.
- [ ] **Composite Foreign Keys**: From Layer 2 onwards, all relationships between school-owned tables enforce `(school_id, id)` composite foreign keys to guarantee database-level tenant isolation:
  - Referenced tables must have a unique index on `(school_id, id)`.
  - Referencing tables must enforce a composite foreign key on `(school_id, parent_id) -> references (school_id, id)`.
  - Never use "set null on delete" on composite foreign keys (use `restrictOnDelete()` or `cascadeOnDelete()`).
  - Explicitly name constraints to prevent exceeding MySQL's 64-character limit.
- [ ] **Audit Log Privacy & Security**:
  - The audit log is strictly append-only (updates and deletes refused by `App\Models\Activity`).
  - Never log passwords, password hashes, reset tokens, invitation tokens, 2FA secrets/recovery codes, or unmasked Aadhaar numbers.
  - School data isolation: audit entries are scoped by `school_id`.
  - Retention: Do not schedule `activitylog:clean` until data retention duration (e.g. accounting, fee and payment audit trails) is confirmed with a CA.
