# CLAUDE.md — Heather Osness

## Project Overview

Heather's personal-brand site at `heather.osness.org`. PHP 8.1+ / Bootstrap 5,
JSON file storage, deployed to IONOS shared hosting via SFTP from GitHub
Actions. Same architectural DNA as `cassie`, `cabin`, `flag-tracker`,
and `sacrament-meeting-planner`.

Currently a minimal playground scaffold — landing page + admin. Grows
into whatever Heather wants to put here (student resources, program
communications, side projects).

## Tech Stack

- **Backend**: Plain PHP (procedural, no framework)
- **Frontend**: Bootstrap 5.3.3 (CDN) + Playfair Display / Inter fonts
- **Storage**: JSON files in `protected/heather/`
- **Deploy**: GitHub Actions → SFTP to IONOS on push to `main`

## Docroot Config (important)

The subdomain **`heather.osness.org`** should be configured with its
document root pointed at **`/heather/public/`** (NOT `/heather/`). This
puts `public/*` at the URL root and keeps `includes/`, `protected/` etc.
physically outside the webroot — no `.htaccess` guardrails needed.

If IONOS ever resets the docroot, requests will 404 and you'll need to
re-point via IONOS → Domains → heather.osness.org → Adjust destination.

## File Layout

```
heather/
├── .github/workflows/deploy.yml    ← auto-deploy on push to main
├── includes/                       ← NOT web-accessible (outside docroot)
│   ├── bootstrap.php               ← session, timezone, path constants
│   ├── csrf.php                    ← token helpers
│   ├── json_store.php              ← read/write/update helpers
│   ├── auth.php                    ← login state + require_login
│   ├── users_service.php           ← admin accounts
│   ├── feedback_service.php        ← change-request queue + GitHub Issues
│   ├── public_header.php + public_footer.php
│   └── admin_header.php + admin_footer.php
├── public/                         ← this IS the webroot
│   ├── index.php                   ← landing page
│   └── admin/
│       ├── index.php               ← dashboard
│       ├── login.php / logout.php
│       ├── setup.php               ← first-run only
│       ├── users.php               ← admin accounts
│       ├── settings.php            ← site name, tagline, GH token
│       └── feedback.php            ← change requests (→ GH Issues)
├── protected/                      ← NOT web-accessible
│   └── heather/                    ← runtime data (gitignored)
│       ├── settings.json
│       ├── users.json
│       └── feedback.json
└── tools/                          ← CLI utilities (excluded from deploy)
```

## Conventions

- Every module: `includes/<name>_service.php` (load/save/find/add/update/delete) +
  `public/[admin/]<name>.php` (page with POST handler + rendering)
- POST-Redirect-GET so refresh doesn't resubmit
- CSRF checked on every state-changing endpoint via `csrf_check()`
- Session keys are `heather_*` (matches the site name; keeps sessions
  distinct from cousin sites if hosted on the same server later)
- `admin_footer.php` and `public_footer.php` ship a universal submit
  spinner that keeps the *submitter* button enabled (Safari/iOS Chrome
  drop the submitter's name/value if disabled mid-submit — never do
  `btn.disabled = true` on a form's submitter)

## Auth model

Single-owner today: every logged-in user is an admin (Heather). The
`is_admin()` helper is there for the day non-admin roles are added
(collaborators, students, whatever); just add a boolean flag to the
user record and gate on it there. No public/customer accounts yet.

## Deployment

- **Trigger**: push to `main`
- **Pipeline**: `.github/workflows/deploy.yml` → `wlixcc/SFTP-Deploy-Action@v1.2.4`
- **Target**: IONOS `home98830486.1and1-data.host` port 22, SFTP
- **SFTP account**: `acc826705176`, scoped to `/heather/`
- **Secrets** (GitHub repo settings):
  - `IONOS_SFTP_USERNAME` = `acc826705176`
  - `IONOS_SFTP_PASSWORD`
- **Behavior**: uploads whole tree minus `.git`, `.github`, `tools/`,
  `README.md`, `CLAUDE.md`, `.gitignore`. `delete_remote_files: false`
  so runtime JSON files under `protected/heather/` are never overwritten.
- **Live URL**: `https://heather.osness.org/`

### Monitoring a deploy
```bash
gh run list --limit 1
gh run watch <run-id>
```

## Change-request workflow (Heather → Jeff → Claude)

Heather can flag anything she wants changed via `/admin/feedback.php` —
a "Send a new request" form on the admin side. Every submission:

1. Saves locally to `protected/heather/feedback.json`.
2. If a GitHub token is configured in Settings, calls the Issues API to
   create an issue in `jeffosness/heather` labeled `heather-feedback`.
   Issue number + URL get stored back on the local record.
3. Heather's list view hydrates status from GitHub live (Under review /
   ✓ Done / Won't do) via a single batch API call.
4. Detail view shows the original request plus all GitHub comments so
   Heather sees Jeff's replies without leaving the site.

**Reviewing Heather's requests in a Claude session:**
- List open: `gh issue list --label heather-feedback --repo jeffosness/heather --state open`
- Read one: `gh issue view <n> --repo jeffosness/heather --comments`
- Reply (Heather sees it on the site): `gh issue comment <n> --repo jeffosness/heather --body "..."`
- Close as done: `gh issue close <n> --repo jeffosness/heather --reason completed`
- Close as won't-do: `gh issue close <n> --repo jeffosness/heather --reason "not planned"`

**Token setup:** `/admin/settings.php` accepts a fine-grained GitHub PAT
scoped to `Issues: read + write` on the `jeffosness/heather` repo.
Stored in `protected/heather/settings.json` (outside webroot, admins-only).
The form never redisplays the token — masked placeholder shows when set.
