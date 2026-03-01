# LibreOffice Preview Setup

RoundDAV can render Office documents in the Files UI using LibreOffice in
headless mode. This enables previews for:

- `.doc`, `.docx`, `.odt`, `.odf`
- `.xls`, `.xlsx`, `.ods`

## Install

Choose your distro:

```bash
# Debian/Ubuntu
sudo apt-get install libreoffice

# RHEL/CentOS/Fedora
sudo dnf install libreoffice

# Alpine
sudo apk add libreoffice

# Arch
sudo pacman -S libreoffice-fresh
```

## Configure

Set the binary path in `config/config.php`:

```php
'files' => [
    // ...
    'libreoffice_path' => '/usr/bin/soffice',
],
```

If `libreoffice_path` is not set, RoundDAV checks common locations:

- `/usr/bin/soffice`
- `/usr/local/bin/soffice`
- `/usr/lib/libreoffice/program/soffice`

## Notes

- The PHP `shell_exec` function must be enabled for previews to work.
- LibreOffice runs in headless mode and converts to HTML for display.
