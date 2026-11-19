# Production Security & Deployment Checklist

This document details mandatory production security and operational requirements for the `edutech` application.

---

## 1. Environment & Database Configuration
- [ ] **`APP_DEBUG=false`**: Ensure debug mode is disabled in production to prevent stack traces, configuration leaks, and environment variable exposure.
- [ ] **`LOG_LEVEL=warning`** (or higher): Set logging level to `warning` or `error` in production to avoid cluttering disk and leaking routine operational data.
- [ ] **Dedicated Database User**: Never use the `root` MySQL user in production. Create a dedicated database user with least-privilege permissions granted strictly on the production database.
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
- [ ] **Role-Based Authorization Only**: Never assign permissions directly to users via `model_has_permissions`. Assign permissions exclusively through roles.
- [ ] **No Platform Admin Bypass**: Never implement a global "platform admin passes every check" gate shortcut. Platform admins must not silently bypass tenant boundaries or educational record privacy.
- [ ] **Composite Foreign Keys**: From Layer 2 onwards, all relationships between school-owned tables enforce `(school_id, id)` composite foreign keys to guarantee database-level tenant isolation.
