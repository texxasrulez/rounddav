# RoundDAV Server
A lightweight CalDAV, CardDAV, and WebDAV storage engine designed for self-hosters who want full control without dragging in a monster stack. RoundDAV powers calendars, contacts, and file storage behind Roundcube — cleanly and predictably.

---

## Features

- **CalDAV & CardDAV**: Standards-compliant calendars and addressbooks
- **WebDAV Filesystem**: Per-user storage rooted at a filesystem path you control
- **Provisioning API**: Roundcube can create DAV users automatically
- **SSO-Ready**: Includes `/public/sso_login.php` and `/public/sso_logout.php`
- **Admin UI**: Simple administration panel for principals, calendars, addressbooks
- **Per-User Extras**: Extra calendars and addressbooks can be created on first login
- **Bookmarks Engine**: Private + domain-shared bookmark storage with a JSON API
- **Clean PHP**: No frameworks, minimal dependencies, easy to debug

---

## Directory Layout

Typical layout on disk:

```text
rounddav/
  config/
    config.dist.php
    config.php
  public/
    index.php
    install.php
    admin/
    files/
    api.php
    sso_login.php
    sso_logout.php
  src/
    Provision/
    Dav/
    ...
```

- `config/` – configuration files
- `public/` – web-exposed entry points (admin UI, files UI, SSO, API)
- `src/` – RoundDAV internals (provisioning, DAV backends, etc.)

---

## Installation

1. Copy `rounddav/` to your server (outside your main vhost if you like).
2. Point a vhost or alias at `rounddav/public/`.

Example Nginx snippet:

```nginx
location /rounddav/ {
    alias /var/www/rounddav/public/;
    index index.php;
}
```

3. Run the installer in your browser:

```text
https://your.server/rounddav/public/install.php
```

4. Fill in:
   - Database DSN / user / password
   - Files root path (for WebDAV file storage)
   - Admin username + password

5. Submit. The installer writes `config/config.php` and initializes the database.

---

## Configuration Overview

`config/config.php` is generated from `config.dist.php` and contains (at least):

```php
return [
    'database' => [
        'dsn'      => 'mysql:host=localhost;dbname=rounddav',
        'user'     => 'rounddav',
        'password' => 'secret',
        'options'  => [],
    ],

    'files' => [
        'root'       => '/srv/rounddav/files',
        'public_url' => 'https://your.server/rounddav/public/files/',
    ],

    'admin' => [
        'username'      => 'admin',
        'password'      => 'admin@example.com',  // used to match admin principal
        'password_hash' => '$2y$10$...',
    ],

    'provision' => [
        'shared_secret'    => 'change_me_provision',
        'principal_prefix' => 'principals',
    ],

    'sso' => [
        'enabled' => true,
        'secret'  => 'change_me_sso',
        'ttl'     => 600,
    ],
];
```

Key points:

- `files.root` – base directory for per-user WebDAV storage.
- `files.public_url` – where the browser reaches the Files UI.
- `admin.password` – used in your templates to identify the admin user.
- `provision.shared_secret` – for API calls if you ever protect them further.
- `sso.secret` – must match `rounddav_sso_secret` in the Roundcube `rounddav_provision` plugin.

---

## Provisioning API

RoundDAV exposes a simple HTTP API for provisioning users:

```text
POST /rounddav/public/api.php?r=provision/user
Content-Type: application/json
```

Example payload (this is what the Roundcube plugin sends):

```json
{
  "username": "user@example.com",
  "password": "plaintext-or-derived",
  "extra_calendars": [
    {
      "uri": "todo",
      "displayname": "Tasks",
      "mode": "tasks",
      "shared": false
    }
  ],
  "extra_addressbooks": [
    {
      "uri": "work",
      "displayname": "Work Contacts",
      "shared": false
    }
  ]
}
```

The server will:

- Ensure the principal and credentials exist
- Ensure the default calendar + addressbook exist
- Ensure any additional calendars/addressbooks defined in the request exist

The endpoint replies with JSON, e.g.:

```json
{
  "status": "ok",
  "message": "Provisioning OK for user@example.com",
  "principal_uri": "principals/user@example.com",
  "principal_id": 6
}
```

---

## Bookmarks API

RoundDAV ships with an opinionated bookmark store that Roundcube (and other clients) can use for private or domain-wide collections. It piggybacks on the existing `public/api.php` entry point:

```
POST /rounddav/public/api.php?r=bookmarks/list
X-RoundDAV-Token: <shared-secret>   # trusted mode
Content-Type: application/json
```

Available routes:

| Route | Description |
|-------|-------------|
| `bookmarks/list` | Returns folders + bookmarks for the user (`include_shared` optional) |
| `bookmarks/create` | Create a bookmark (`title`, `url`, `visibility`, optional folder / tags / notes) |
| `bookmarks/update` | Update an existing bookmark (`id`, plus fields to change) |
| `bookmarks/delete` | Remove a bookmark (`id`) |
| `bookmarks/folder/create` | Create a folder (supports private or shared scopes) |
| `bookmarks/folder/update` | Rename / reparent a folder |
| `bookmarks/folder/delete` | Delete a folder (bookmarks move to root) |

Authentication options:

1. **Trusted** (`X-RoundDAV-Token`) – for Roundcube plugins on the same server. The token reuses `provision.shared_secret` unless you set `bookmarks.shared_secret`.
2. **HTTP Basic** – for standalone clients (e.g. browser extensions) using the RoundDAV username/password. Permissions are enforced per user/domain.

Config block (`config/config.php`):

```php
'bookmarks' => [
    'enabled'                => true,
    'max_private_per_user'   => 1000,
    'max_shared_per_domain'  => 4000,
    'shared_default_enabled' => true,
    'shared_default_label'   => 'Shared Bookmarks',
    'max_favicon_bytes'      => 24576,
    'max_inline_icon_bytes'  => 8192,
    'favicon_timeout'        => 4,
    'shared_secret'          => '', // optional override
],
```

Database tables are added via `config/rounddav.mysql.sql`:

- `rounddav_bookmark_domains` – per-domain overrides (limits, labels, shared toggle)
- `rounddav_bookmark_folders` – hierarchical folder tree (private or shared scope)
- `rounddav_bookmarks` – bookmark records + favicon cache

All shared bookmarks are automatically scoped to the user’s email domain (e.g. `example.com`). Users without an `@domain` fall into the special `Local users` bucket (`BookmarkService::LOCAL_DOMAIN`).

---

## SSO Endpoints

These are used by Roundcube (`rounddav_provision` + `rounddav_files`) to log users into the web UI without a second login.

### Login

```text
GET /rounddav/public/sso_login.php?user=<user>&ts=<unix>&sig=<hmac>
```

- `user` – principal identifier (usually the email / Roundcube username)
- `ts` – Unix timestamp when the token was issued
- `sig` – `hash_hmac('sha256', "$user|$ts", $config['sso']['secret'])`

If valid:

- Sets `$_SESSION['rounddav_files_user'] = $user`
- Redirects the browser to `files.public_url` or `./files/`

### Logout

```text
GET /rounddav/public/sso_logout.php?user=<user>&ts=<unix>&sig=<hmac>
```

- Signature is based on `"$user|$ts|logout"`

If valid:

- Destroys the PHP session
- Leaves no visible output

Roundcube’s logout hook fires a tiny `new Image().src = ".../sso_logout.php?...";` call to trigger this.

---

## Admin UI

Accessible under:

```text
https://your.server/rounddav/public/admin/
```

You can:

- View and manage principals
- Create/delete calendars and addressbooks for each principal
- Edit calendar/addressbook properties (URI, displayname, flags)
- Configure domain-level bookmark sharing rules/limits
- Toggle options like “tasks only” vs “events only” vs both

The Admin UI is intentionally minimal, built for admins who already know what DAV is.

---

## Files UI

Accessible under:

```text
https://your.server/rounddav/public/files/
```

This is the generic Files interface that `rounddav_files` embeds in an iframe inside Roundcube. It:

- Shows per-user directories under your configured `files.root`
- Allows uploads, downloads, deletions (depending on your implementation)
- Is styled to roughly match Roundcube skins when embedded

---

## Philosophy

RoundDAV is meant to be:

- Small enough to understand
- Strong enough to be useful
- Quiet enough to disappear behind Roundcube

If you know Roundcube and a bit of PHP, you should be able to debug or extend this without fighting it.
