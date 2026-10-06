# API — Authentication

Status: implemented (Prompt 07). Base path `/api/v1`.

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| POST | `/auth/register` | public | Create account → `201` + session cookie (auto sign-in) |
| POST | `/auth/login` | public | Sign in → `200` + session cookie |
| POST | `/auth/logout` | session | Delete current session → `200`, cookie cleared |
| GET | `/auth/me` | session | Own profile only |
| GET | `/auth/sessions` | session | Own active sessions (`is_current` flagged) |
| DELETE | `/auth/sessions/{session_id}` | session | Revoke one of **your own** sessions → `404` for anyone else's |
| POST | `/auth/password/forgot` | public | Request reset — always generic `200` |
| POST | `/auth/password/reset` | public | Consume token, set password, revoke **all** sessions |

Envelope is always `{ok, data, errors}`; `401` unauthenticated, `422` validation, `429` lockout.

## Security model

| Threat | Defense |
| --- | --- |
| Insecure password storage | `password_hash()` (bcrypt/PASSWORD_DEFAULT), rehash-on-login upgrade, 8–72 char policy |
| Session fixation | 32 random bytes per login, old session discarded, server-side table, cookie replaced every sign-in |
| Insecure cookies | `HttpOnly`, `SameSite=Lax`, `Path=/`, `Secure` on HTTPS (incl. `X-Forwarded-Proto`) |
| Session theft/leaks | only SHA-256 hashes stored; raw token exists only in the cookie; hashes never serialized or logged |
| Unauthorized access | `AuthMiddleware` resolves identity **only** from the cookie — client-supplied user ids are never trusted |
| Cross-user leakage | every query is scoped `WHERE user_id = <session user>`; profile/session payloads contain no hashes; revocation of a foreign id returns `404` |
| Brute force | per-account counter → lockout (`AUTH_MAX_FAILED_ATTEMPTS` / `AUTH_LOCKOUT_MINUTES`, default 5 / 15 min) with `429`. The lock timestamp is written in UTC and compared as UTC; once it expires the failed-attempt counter is cleared, so the next mistake starts a fresh window instead of re-locking instantly. Accepted trade-off: `429` reveals that *some* account is locked (prevents a successful-password user being locked out by a stranger), which is preferred over the alternative. |
| Account enumeration | identical `401` message for unknown email vs wrong password; dummy hash + fixed 200 ms delay; forgot-password always generic `200` **and padded to the same elapsed time** (both request paths apply the same delay); register/update-profile answer `422` ("Email is already registered") even when the duplicate arrives as a concurrent-insert UNIQUE violation, never `500` |
| Stolen reset token | single-use atomic claim, 60 min TTL (`PASSWORD_RESET_TTL_MINUTES`), hash-only storage, all sessions revoked on reset |

## Password reset flow

1. `POST /auth/password/forgot {email}` → token (raw) handed to `PasswordResetNotifierInterface`, SHA-256 hash persisted.
2. Default notifier (`LogPasswordResetNotifier`) records *that* a reset happened in `storage/logs/password-reset.log` — **never the token**. SMTP delivery plugs into the same interface when mail is configured.
3. `POST /auth/password/reset {token, password}` → validates, claims token atomically, updates password, deletes every session for that user.

## Ownership rules

- User identity comes exclusively from the session cookie (`AuthMiddleware` → request attribute `user`).
- Repositories add `AND user_id = ?` for every user-scoped statement (`SessionRepository::deleteForUser`).
- Absence of another user's resource is reported as `404`, never `403`, so existence is not revealed.

## Config (`.env`)

`SESSION_NAME`, `SESSION_LIFETIME` (minutes), `AUTH_MAX_FAILED_ATTEMPTS`, `AUTH_LOCKOUT_MINUTES`, `PASSWORD_RESET_TTL_MINUTES`.
