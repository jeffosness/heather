# heather.osness.org

Heather's playground site. PHP + Bootstrap + JSON storage, deployed via
GitHub Actions to IONOS shared hosting.

See [CLAUDE.md](CLAUDE.md) for the full architecture and workflow docs.

## First-time setup

1. Point the `heather.osness.org` subdomain's document root at `/heather/public/`
   in IONOS.
2. Push to `main` to auto-deploy (needs `IONOS_SFTP_USERNAME` + `IONOS_SFTP_PASSWORD`
   in GitHub repo secrets).
3. Visit `https://heather.osness.org/admin/setup.php` — first run creates the
   admin account.

## Change requests

Heather submits feature/fix requests at `/admin/feedback.php`. Each one
becomes a GitHub issue in this repo (labeled `heather-feedback`) so Jeff
sees a notification and can act. Status flows back to her admin so she
knows when it's under review, done, or set aside.

Requires a fine-grained GitHub PAT scoped to `Issues: read + write` on
this repo, pasted into `/admin/settings.php`.
