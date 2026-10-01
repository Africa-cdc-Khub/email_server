# Pending retry, OTP priority, Supervisor workers & dashboard quotas

**Date:** 2026-10-01  
**Status:** Approved — implementing  
**Surface:** Queue workers, scheduler, Security/System settings, dashboard (`https://notifications.africacdc.org/`)

## Problem

1. Stuck `pending` email logs (lost Redis jobs / worker restarts) sit until an admin clicks “Send all pending”.  
2. Login OTP messages (“sign-in code:” / “verification code”) compete with bulk mail.  
3. A single `queue:work` process is fragile; ~4 concurrent Exchange sends need several workers.  
4. Dashboard should show **pending count** and **remaining quota per mailbox group** before caps are hit.

## Decisions (locked)

| Topic | Choice |
|---|---|
| Auto-retry pending | Scheduled command re-queues pending logs that still have a stored body (same as bulk “Send all pending”) |
| Default interval | **60 seconds**; configurable; **0 = disabled** |
| Settings storage | DB key/value (or extend a small `system_settings` / branding-adjacent settings row): `mail_pending_retry_seconds` default `60` |
| Settings UI | Admin Security / System settings: “Re-queue stuck pending emails every N seconds” |
| OTP priority | Subject matches `/sign-in code:|verification code/i` → Redis queue `emails-priority`; others → `emails` |
| Worker process manager | **Supervisor** inside the Docker `queue` container (`numprocs=4`), listening `emails-priority,emails,default` |
| Scheduler | `schedule:work` (or `schedule:run` every minute via a Supervisor program) in the same queue container |
| Dashboard pending | Show count of `email_logs` with `status = pending` (admin; scoped users respect integration ACL) |
| Dashboard quotas | Keep/enhance mailbox quotas by provider: remaining (24h + 1h when set) emphasized so operators see headroom before exhaustion |
| Manual UI | Keep “Send all pending” on logs |

## Approach

### 1. Priority dispatch

When queueing `SendEmailJob`, set queue name:

- `emails-priority` if subject matches OTP patterns (case-insensitive).  
- else `emails`.

Workers: `--queue=emails-priority,emails,default`.

### 2. Auto-retry command + schedule

`php artisan emails:retry-pending`:

- Reads `mail_pending_retry_seconds` (or skips if 0).  
- Calls existing `EmailDispatchService::retryAllPending()`.  
- Schedule: every minute in `routes/console.php`; command itself no-ops if last run was &lt; configured interval (or schedule uses conditional). Prefer schedule every minute + command checks interval elapsed via cache timestamp.

### 3. Supervisor in queue container

- Install `supervisor` in Dockerfile (or use a slim supervisord image layer).  
- Config: 4× `queue:work`, 1× `schedule:work`.  
- `entrypoint.sh` for `CONTAINER_ROLE=queue` starts `supervisord` instead of a single `queue:work`.  
- Compose `QUEUE_SCALE` remains optional; default one queue container with 4 procs.

### 4. Dashboard

- API `GET /admin/dashboard`: add `stats.emails_pending` (and optionally `emails_pending_today`).  
- Existing `mailbox_quotas` already has `remaining_24h` / `remaining_1h` — ensure UI shows remaining prominently per provider group (not only progress bars).

### 5. Settings API

- `GET/PUT` system mail settings (admin): `{ mail_pending_retry_seconds: number }`.  
- Validation: integer 0–3600 (or 0–86400).

## Out of scope

- Changing Exchange Graph throttling beyond existing mailbox quotas  
- Horizon dashboard  
- Host-level systemd for queue (Docker Supervisor only)

## Success criteria

1. Pending stuck jobs are re-queued automatically at the configured interval (default 60s).  
2. OTP-subject emails are processed before normal mail when both are queued.  
3. Queue container runs 4 Supervisor workers + scheduler and survives worker crashes.  
4. Dashboard shows pending count and remaining quota per provider mailbox group.  
5. Feature tests cover priority queue selection, retry command, and dashboard stats.

## Spec self-review

- Interval configurable; default 60; 0 disables.  
- Fallback/quota rules unchanged.  
- No MySQL; Postgres-compatible Eloquent only.
