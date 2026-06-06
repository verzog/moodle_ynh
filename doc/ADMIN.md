## Update process

This package uses two GitHub Actions workflows to manage Moodle updates.

### Patch releases (automatic)

A workflow runs daily at 06:00 UTC and checks for new patch releases within the currently installed major.minor series (e.g. `5.1.3` → `5.1.4`). If a newer patch is found it opens a pull request on this repository with the updated version, download URL, and SHA256.

To apply a patch update after merging the PR:

```bash
yunohost app upgrade moodle -u "https://github.com/verzog/moodle_ynh"
```

### Minor/major releases (manual decision required)

A workflow runs weekly and opens a GitHub **issue** when a new Moodle series is detected (e.g. `5.1` → `5.2`). The issue includes a checklist of compatibility requirements to verify before upgrading, including PostgreSQL and PHP version requirements.

> **Important:** Moodle 5.2+ requires PostgreSQL 16. YunoHost does not support in-place PostgreSQL major version upgrades. Do not upgrade Moodle to a new series until you have confirmed your server meets its requirements.

To upgrade to a new series when ready:

1. Check the issue checklist — verify PostgreSQL and PHP version compatibility
2. Update `manifest.toml` manually — change `version` to `X.Y.0~ynh1` and update the download URL and SHA256
3. The patch update workflow will automatically track the new series from that point

### Configuration panel

The YunoHost admin panel exposes the following settings under **Apps → Moodle → Config**:

| Setting | Default | Notes |
|---|---|---|
| PHP upload max file size | 1G | Synced to nginx `client_max_body_size` |
| PHP memory limit | 256M | Per PHP-FPM worker |
| PHP max execution time | 300s | Increase for large course restores |
| PHP max input vars | 5000 | Moodle minimum is 5000 |
| Cron interval | 15 min | How often background tasks run (1, 2, 5, 10, 15, or 30 minutes) |

Changes take effect immediately — php-fpm and nginx are reloaded automatically.

## Troubleshooting

### "The URL is blocked" when a plugin fetches the site's own URL

You may see errors like this, typically on the **Benchmark report** page
(`/report/benchmark/`) or other plugins that request the site's own address:

```
Blocked https://<your-domain>/admin/index.php?cache=1: The URL is blocked.
...
cURL request for "https://<your-domain>/..." failed, HTTP response code: Unknown cURL error
Warning: Undefined array key "http_code" in .../lib/filelib.php
```

This is **Moodle's built-in cURL SSRF protection** (`curlsecurityblockedhosts`),
not a packaging bug. Recent Moodle versions ship stricter default deny-lists
(see [MDL-56873](https://tracker.moodle.org/browse/MDL-56873)) that block
requests to loopback and private IP ranges (`127.0.0.1`, `10.0.0.0/8`,
`192.168.0.0/16`, …). On a single-server install your domain resolves to one of
those addresses, so Moodle refuses the self-request before it gets an HTTP
response — which also produces the secondary `http_code` / "Unknown cURL error"
warnings.

It is **harmless**: it only affects plugins that try to download the site's own
URL (such as the third-party Benchmark report). Normal Moodle operation does not
require the server to fetch its own public URL.

If you specifically need such a plugin to work, go to
**Site administration → General → Security → HTTP security** and remove the
loopback/private entry that matches your server from **cURL blocked hosts**
(`curlsecurityblockedhosts`).

> ⚠️ This relaxes SSRF protection for **all** outbound cURL calls Moodle makes
> (file picker, repositories, OAuth, etc.), so only do it if you genuinely need
> it. Leaving the default in place is recommended.
