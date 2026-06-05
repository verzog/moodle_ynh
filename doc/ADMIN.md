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
| Cron interval | 15 min | How often background tasks run |

Changes take effect immediately — php-fpm and nginx are reloaded automatically.
