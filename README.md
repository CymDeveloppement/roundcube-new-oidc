# Roundcube New OIDC

Fork of [roundcube-oidc](https://github.com/pulsejet/roundcube-oidc) with OIDC logout and auto-redirect support.

This plugin allows you to authenticate users to Roundcube using an OpenID Connect 1.0 provider. There are three modes to run the plugin in:
1. **Cleartext Password**: The OIDC provider must supply the user's password in cleartext, which is then used to login to the IMAP server
2. **Master Password**: In this mode (also falls back to this), a master password is used to login to the IMAP server with the username obtained from OIDC
3. **Master User**: IMAP authentication is done using a master user ([Dovecot](https://doc.dovecot.org/configuration_manual/authentication/master_users/)) with a provided separator

## Installation

```bash
composer require cymdeveloppement/roundcube-new-oidc
```

Then copy and edit the configuration file:
```bash
cp plugins/roundcube_oidc/config.inc.php.dist plugins/roundcube_oidc/config.inc.php
```

## Configuration

### OIDC Provider

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `oidc_url` | string | `''` | URL of the OIDC provider |
| `oidc_client` | string | `''` | Client ID registered on the provider |
| `oidc_secret` | string | `''` | Client secret for the given client ID |
| `oidc_scope` | string | `'openid'` | OIDC scope to request |

### IMAP Authentication

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `oidc_imap_master_password` | string | `''` | Master password fallback if the provider does not supply a cleartext password |
| `oidc_master_user_separator` | string | `'*'` | Separator for Dovecot master user authentication |
| `oidc_config_master_user` | string | `''` | Master user to append after separator. Leave blank to disable |

### User Fields Mapping

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `oidc_field_uid` | string | `'mail'` | OIDC claim for login UID (typically an email) |
| `oidc_field_password` | string | `'password'` | OIDC claim for cleartext password |
| `oidc_field_server` | string | `'imap_server'` | OIDC claim for IMAP server address |

### Login Page

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `oidc_login_page` | string | `''` | Path to an alternative login page. Errors are available as `$ERROR` |
| `oidc_auto_redirect` | bool | `false` | Automatically redirect to OIDC provider, bypassing the login page |

### Logout

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `oidc_logout_url` | string | `''` | OIDC provider logout URL for Single Logout support |

Example for Keycloak:
```php
$config['oidc_logout_url'] = 'https://auth.example.com/realms/REALM/protocol/openid-connect/logout?post_logout_redirect_uri=https%3A%2F%2Fmail.example.com&client_id=roundcube';
```

## SMTP

Unless cleartext passwords are provided, SMTP must be configured to use no authentication or a master password.

## Compatibility

- Roundcube 1.6+
- PHP 8.0+
- Tested with Keycloak 25+ as OIDC provider

## License

MIT License
