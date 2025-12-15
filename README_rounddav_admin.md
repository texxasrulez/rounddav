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
    'password'      => 'admin@example.com',  // also used in templates for links
    'password_hash' => '$2y$10$...',
],
```

The `password_hash` is a standard `password_hash()` result. The `password` value is available for templates that want to show an Admin link only for the admin user.

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
