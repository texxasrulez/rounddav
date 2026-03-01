# RoundDAV Security Smoke Tests

Run the smoke checks with:

```bash
php rounddav/tests/security_smoke.php
```

Run HTTP integration smoke checks with:

```bash
bash rounddav/tests/integration_http_smoke.sh
```

The script validates a few high-impact hardening guards:

- CSRF token validation and hidden token fields in Files/Admin POST flows
- SSO log redaction (no token-bearing parameter dumps)
- No cleartext session password write in `rounddav_provision` authenticate flow
- Canonical path boundary checks in `rounddav_files` local attach resolver

The HTTP integration script validates end-to-end behavior for:

- Provisioning API success, invalid token, and validation error responses
- SSO login/logout with valid, expired, and bad signatures
- Files UI CSRF enforcement on POST actions
