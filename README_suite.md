# Roundcube–RoundDAV Suite
Turn Roundcube into a real personal groupware environment: email, calendars, contacts, and files, all with one login.

This suite combines:

1. [**RoundDAV Server**](https://github.com/texxasrulez/rounddav) — CalDAV, CardDAV, WebDAV + Admin UI
2. [**rounddav_provision**](https://github.com/texxasrulez/rounddav_provision) — Roundcube plugin for provisioning + SSO
3. [**rounddav_files**](https://github.com/texxasrulez/rounddav_files) — Roundcube plugin for Files tab + attachments

Together, they make Roundcube behave like a complete PIM stack instead of “just webmail.”

---

## Components

### 1. RoundDAV

- Provides CalDAV, CardDAV, and WebDAV endpoints
- Stores per-user files under a filesystem root
- Has a small Admin UI for managing principals, calendars, addressbooks
- Includes SSO endpoints used by Roundcube

See `README_rounddav.md` for full details.

### 2. rounddav_provision (Roundcube plugin)

- Hooks into Roundcube login
- Automatically creates a RoundDAV user on first login
- Ensures default calendar + addressbook exist
- Creates extra calendars and addressbooks as configured
- Generates a one-shot SSO login URL and stores it in the session
- Handles logout-time SSO cleanup

See `README_rounddav_provision.md` for full details.

### 3. rounddav_files (Roundcube plugin)

- Adds a **Files** tab in Roundcube
- Embeds the RoundDAV Files UI via iframe
- Uses the SSO URL prepared by `rounddav_provision` for transparent login
- Adds an **Attach from RoundDAV** button to the compose screen

See `README_rounddav_files.md` for full details.

---

## High-Level Flow

```text
[User logs into Roundcube]
        │
        ▼
rounddav_provision
  - Captures credentials (authenticate hook)
  - Calls RoundDAV provisioning API
  - Creates extra calendars/addressbooks (if configured)
  - Generates SSO login URL for Files UI
  - Stores URL in $_SESSION['rounddav_sso_login_url']
        │
        ▼
rounddav_files
  - When user clicks “Files”:
    - Uses SSO URL once → /sso_login.php → Files UI
    - Later uses plain Files URL as long as DAV session is alive
        │
        ▼
RoundDAV Server
  - Stores calendars, contacts, files
  - Admin UI for management
```

---

## Quick Setup Checklist

### 1. Install RoundDAV

- Deploy the `rounddav/` directory
- Point a web path to `rounddav/public/`
- Run `install.php`
- Configure database and files root
- Set admin credentials
- Ensure `config/config.php` is in place

### 2. Install `rounddav_provision`

- Copy plugin into `roundcube/plugins/rounddav_provision/`
- Enable in Roundcube config:

  ```php
  $config['plugins'][] = 'rounddav_provision';
  ```

- Copy `config.inc.php.dist` → `config.inc.php`
- Set:

  ```php
  $config['rounddav_api_url']   = 'https://your.server/rounddav/public/api.php';
  $config['rounddav_base_url']  = 'https://your.server/rounddav/public';
  $config['rounddav_sso_secret'] = 'same_secret_as_rounddav_config';
  $config['rounddav_sso_enabled'] = true;
  ```

- Optionally configure `rounddav_extra_calendars` and `rounddav_extra_addressbooks`.

### 3. Install `rounddav_files`

- Copy plugin into `roundcube/plugins/rounddav_files/`
- Enable in Roundcube config:

  ```php
  $config['plugins'][] = 'rounddav_files';
  ```

- Configure:

  ```php
  $config['rounddav_files_url'] = 'https://your.server/rounddav/public/files/?user=%u';
  ```

### 4. Test

1. Create a new Roundcube user.
2. Log in.
   - RoundDAV user is created automatically.
   - Default + extra calendars/addressbooks are created.
3. Click **Files** in Roundcube.
   - You should land in the RoundDAV Files UI without an extra login.
4. Try sending an email and use **Attach from RoundDAV**.

If something goes sideways, check:

- Roundcube `logs/rounddav` and `logs/roundcube`
- PHP error logs for RoundDAV

---

## Why This Suite Exists

There are many big stacks that try to do everything:

- Nextcloud
- SOGo
- Kopano
- etc.

They are powerful, but heavy.

This suite takes a different approach:

- Roundcube remains the front-end.
- RoundDAV is a slim back-end for DAV + files.
- Two small plugins glue them together with SSO and provisioning.

The result is:

- Understandable
- Maintainable
- Fast
- Perfect for home labs, power users, and small deployments that want control instead of abstraction layers.

---

## Future Ideas

- Real shared/global collections (calendars and addressbooks)
- Per-domain or per-group provisioning templates
- Quotas and usage reporting in the Admin UI
- Optional public links for files
- Time-based auto-provisioning rules

---

## License / Usage

Use it, fork it, abuse it (responsibly).

The point of this stack is to give you a Roundcube-centric, DAV-powered environment that you fully own and understand.
