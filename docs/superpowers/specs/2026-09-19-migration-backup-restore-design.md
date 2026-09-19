# Migration backup & restore

**Date:** 2026-09-19  
**Status:** Implemented (2026-09-19)  
**Surface:** Admin panel (`/backup`) — admins only

## Problem

When moving Email Server Admin to a new host, operators need a reliable way to carry over:

1. User accounts (including partner registrations)
2. Integration clients (credentials that connecting systems already use)
3. Email provider connection details (SMTP / Exchange / etc.)
4. User ↔ client ownership links
5. Branding (colors, names, logos)

A raw MySQL dump is a poor fit: provider `config` is encrypted with `APP_KEY`, so a different key on the new server breaks decryption. Client secrets are stored as hashes (plaintext cannot be recovered), but the hash must travel so existing `client_id` + secret pairs keep working.

## Decisions (locked)

| Topic | Choice |
|---|---|
| Portability | **A** — portable package with **decrypted** provider secrets; re-encrypt on import with the destination `APP_KEY`. Client secrets remain **hashes** (existing credentials keep working; secrets cannot be re-displayed). |
| Restore UX | **1** — Admin UI download + upload (app applies upsert). No reliance on `mysql` CLI import for this flow. |
| Scope | **C** — users, clients, providers, user↔client links, branding (including logo/favicon bytes). |
| Excluded | Email logs, audit logs, blocked IPs/emails, password-reset tokens, Sanctum personal access tokens. |

## Approach

**Versioned JSON migration package** + admin **Backup / Restore** page.

Do not use mysqldump for this feature. The application owns encrypt/decrypt and upsert rules.

## Package format

Filename: `email-server-migration-YYYYMMDD-HHMMSS.json`

```json
{
  "meta": {
    "schema_version": 1,
    "exported_at": "2026-09-19T18:00:00+00:00",
    "app_name": "Email Server",
    "source_app_url": "https://notifications.africacdc.org"
  },
  "email_providers": [ /* ... */ ],
  "users": [ /* ... */ ],
  "external_integrations": [ /* ... */ ],
  "user_client_links": [
    { "user_email": "partner@example.com", "client_slug": "my-app" }
  ],
  "branding": { /* ... */ }
}
```

### Match keys (natural IDs)

| Entity | Match key | Notes |
|---|---|---|
| Email provider | `slug` | Insert if missing; update if present |
| User | `email` | Insert if missing; update if present |
| Client | `slug` | Insert if missing; update if present |
| Links | user email + client slug | After users/clients imported, sync links for users present in the package |
| Branding | singleton row | Overwrite fields; restore binary assets when provided |

### Field rules

**Email providers**

- Export: decrypt `config` via `safeConfig()` / model accessors so the JSON contains usable credentials.
- Import: write through the model so `config` is re-encrypted with the destination `APP_KEY`.
- Preserve `driver`, from address/name, `is_default`, `is_active`, `priority`, `description`.
- If multiple providers claim `is_default`, the last one imported as default wins (clear others).

**Users**

- Export: include password hash, approval fields, phone, organisation, registration_source, admin/active flags, TOTP-related columns needed for login continuity (`two_factor_*`, `totp_required`, encrypted secret + recovery codes as exportable ciphertext or re-exportable values the model can restore).
- Import: **create** when email is absent; **update** when email exists (profile, approval, flags, password hash, 2FA fields from package).
- Do not delete users absent from the package.
- `created_by` / `approved_by`: resolve by email if the referenced user is in the package or already on the destination; otherwise null.

**External integrations (clients)**

- Export: `name`, `slug`, `api_key_hash`, `api_key_prefix`, `allowed_ips`, `settings`, `is_active`, `description`, provider reference by **provider slug** (not numeric id).
- Import: upsert by `slug`; update hash/prefix/settings/active/provider link so existing client credentials continue to authenticate.
- Do not regenerate secrets on import.

**User ↔ client links**

- Export as `{ user_email, client_slug }` pairs.
- Import: for each user appearing in the package links, `sync` their client set to the package set (clients resolved by slug). Users not mentioned in links are left unchanged.

**Branding**

- Export scalar fields (`primary_color`, `secondary_color`, `app_name`, logo size, etc.).
- Export `logo`, `logo_dark`, `favicon` as optional objects `{ "filename": "...", "mime": "...", "base64": "..." }` when files exist on disk.
- Import: overwrite branding row; write files into the normal branding storage paths used by `BrandingSetting`.

## API

Admin-only (`EnsureUserIsAdmin`):

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/admin/migration/export` | Download JSON package (`Content-Disposition: attachment`) |
| `POST` | `/api/v1/admin/migration/import` | Multipart upload of JSON; returns summary |

### Import response shape

```json
{
  "message": "Migration imported.",
  "data": {
    "providers": { "created": 1, "updated": 2 },
    "users": { "created": 3, "updated": 1 },
    "clients": { "created": 0, "updated": 4 },
    "links_synced": 5,
    "branding_updated": true,
    "warnings": []
  }
}
```

## UI

- Route: `/backup` (name: `backup`), admin-only (same gate as other admin tools).
- Sidebar: **Backup / Restore** (e.g. `mdi-database-export`).
- Page actions:
  - **Download migration package**
  - File picker + **Restore** with confirm dialog warning that the file contains secrets and will upsert matching records
  - Result summary (created/updated counts + warnings)

## Safety & ops

- Confirm before import.
- Audit events: `migration_exported`, `migration_imported` (no secret values in audit payloads).
- Throttle import (e.g. 5/hour) and export (e.g. 20/hour).
- Max upload size aligned with nginx (`20M`); reject non-JSON / wrong `schema_version`.
- Run import inside a DB transaction; roll back on hard failures.
- Soft warnings (e.g. missing provider slug for a client) collected in `warnings` without aborting the whole import when the row can be skipped safely; abort + rollback on structural/validation errors.

## Security notes

- The download is equivalent to a credentials dump. UI copy must say so.
- Only admins can export/import.
- Destination server may use a different `APP_KEY`; that is intentional for Approach A.

## Out of scope

- Scheduled/automatic backups
- Restoring email logs or audit history
- Re-issuing plaintext client secrets
- CLI `mysql` import path for this package

## Success criteria

1. Admin can download a package from server A and restore it on server B with a different `APP_KEY`.
2. Provider SMTP/Exchange credentials work on B after import.
3. Existing client_id + client_secret pairs still obtain JWTs on B.
4. Users missing on B are created; existing users/clients matched by email/slug are updated.
5. Partner user ↔ client links and branding (including logos when present) are restored.
6. Feature tests cover export shape and import upsert behaviour (create + update paths).
