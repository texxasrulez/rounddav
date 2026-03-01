# RoundDAV Admin UI
The RoundDAV Admin UI is a minimal web interface for managing users (principals), calendars, and addressbooks on your RoundDAV server.

It is designed for admins who want a clear, focused panel instead of a bloated groupware dashboard.

---

## Location

The Admin UI is served under:

```text
https://your.server/rounddav/public/admin/
```

You can protect this URL at the webserver level (HTTP auth, IP allowlist, etc.) in addition to the built-in RoundDAV admin authentication.

---

## Authentication

The Admin UI uses the admin credentials configured in `config/config.php`:

```php
'admin' => [
    'username'      => 'admin',
    'email'         => 'admin@example.com',
    'password_hash' => '$2y$10$...',
],
```

The `password_hash` is a standard `password_hash()` result. Use `admin.email` (or `admin.username`) when templates need to decide whether to show admin-only links.

---

## Features

### Principals

- List all DAV principals (users)
- View principal URIs and IDs
- See associated calendars and addressbooks

### Calendars

For each principal:

- List calendars with:
  - URI
  - Display name
  - Internal ID
  - Component mode (events/tasks/both)
- Create new calendars
- Edit calendar properties:
  - Display name
  - URI
  - Description
  - Component mode (`VEVENT`, `VTODO`, or both)
- Delete calendars (where allowed)

### Addressbooks

For each principal:

- List addressbooks with:
  - URI
  - Display name
  - Internal ID
- Create new addressbooks
- Edit addressbook properties (display name, URI)
- Delete addressbooks (where allowed)

### Bookmarks

- Monitor per-domain bookmark counts (private vs shared)
- Configure domain-level sharing policies (enable/disable shared bookmarks, per-domain limits, custom labels)
- Override defaults for users without a domain (local logins)
- Remove overrides if a domain should fall back to global config

---

## Layout & UI

The Admin UI is intentionally simple:

- One main page listing principals
- Expand/collapse or sections for calendars and addressbooks per principal
- Buttons for **Add**, **Edit**, **Delete** actions
- Uses standard HTML with light CSS so that you can easily restyle it or embed it in other admin environments

The goal is clarity and speed, not fancy widgets.

---

## Integration with Roundcube

While the Admin UI is independent of Roundcube, you can:

- Add a link to the Admin UI in Roundcube **Settings** for the primary admin user.
- In the RoundDAV Files UI (`public/files/index.php`), show or hide an **Admin** link based on whether the current user matches the configured admin email or a specific rule.

Example snippet (Files UI):

```php
$current_user_id = $_SESSION['rounddav_files_user'] ?? null;
$admin_id = 'admin@example.com'; // or pulled from config

if ($current_user_id === $admin_id) {
    echo "<a href='https://your.server/rounddav/public/admin/' target='_blank'>Admin</a> |";
}
```

This keeps the admin controls available but not exposed to regular users.

---

## Future Directions

The Admin UI is intentionally small, but can be extended with:

- Global/shared collection management (using `rounddav_global_*` tables)
- Per-group or per-domain default templates
- Quota management for file storage
- Logs and diagnostics panel

Because the UI is just PHP + HTML, it’s straightforward to adjust it to your exact hosting environment.

---

## Config Backup, Restore, and Retention

In **Server configuration** (`/public/admin/?action=config`), the **Config Snapshot** area includes operational controls for `config/config.php`.

### Backup and restore actions

- **Create backup now** creates a timestamped backup in `var/config_backups/`
- **Download** exports one selected backup
- **Restore** replaces the live `config.php` with a selected backup
- **Download all as ZIP** exports all backups in one archive for off-host storage

RoundDAV also creates a backup automatically before config writes/imports/restores.

### Retention policy

Retention can be configured in the same card:

- `backups.config.keep_last` (minimum count to keep)
- `backups.config.max_age_days` (age cutoff in days, `0` disables age pruning)
- `backups.config.auto_prune` (prune automatically after config writes/restores/backups)

Default template values:

```php
'backups' => [
    'config' => [
        'keep_last' => 30,
        'max_age_days' => 0,
        'auto_prune' => true,
    ],
],
```

Use **Prune now** to run retention cleanup immediately.

### Roundcube embed-safe security header guidance

If the Files UI is embedded in Roundcube via iframe (especially cross-origin):

- Keep `security.headers.enabled = true`
- Keep `security.headers.x_content_type_options = true`
- Keep `security.headers.referrer_policy = strict-origin-when-cross-origin`
- Keep `security.headers.x_frame_options = ''` for cross-origin embeds
- If CSP is enabled, ensure `frame-ancestors` includes your Roundcube origin (for example `https://mail.example.com`)

Use **Verify Current Headers** in Security diagnostics to compare expected vs live headers on Admin and Files endpoints.
