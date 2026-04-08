# Changelog - Fork roundcube_oidc

## Modifications apportees

### 1. Lecture des claims depuis l'id_token

**Fichier:** `roundcube_oidc.php` (fonction `loginform`)

**Probleme:** Le plugin original ne lit que le endpoint `userinfo` pour recuperer les attributs utilisateur. Certains providers (Keycloak) ne renvoient pas les attributs custom (ex: `imap_password`) via userinfo, meme avec les mappers configures.

**Modification:** Apres `requestUserInfo()`, decoder le `id_token` (JWT) et fusionner ses claims avec les donnees userinfo. Cela permet de recuperer les attributs custom presents dans le token mais absents du endpoint userinfo.

```php
// Apres:
$user = json_decode(json_encode($oidc->requestUserInfo()), true);

// Ajoute:
$idToken = $oidc->getIdToken();
if ($idToken) {
    $parts = explode('.', $idToken);
    if (count($parts) >= 2) {
        $claims = json_decode(base64_decode($parts[1]), true);
        if (is_array($claims)) {
            $user = array_merge($claims, $user);
        }
    }
}
```

---

### 2. Deconnexion OIDC (Single Logout)

**Fichier:** `roundcube_oidc.php` (fonction `init`)

**Probleme:** Le plugin original ne gere pas la deconnexion cote OIDC. Quand l'utilisateur se deconnecte de Roundcube, sa session Keycloak reste active.

**Modification:** Ajout d'un hook `logout_after` qui redirige vers le endpoint de logout du provider OIDC.

```php
// Dans init():
$this->add_hook('logout_after', array($this, 'oidc_logout'));

// Nouvelle fonction:
function oidc_logout($args) {
    $rcmail = rcmail::get_instance();
    $logout_url = $rcmail->config->get('oidc_logout_url', '');
    if (!empty($logout_url)) {
        header('Location: ' . $logout_url);
        exit;
    }
    return $args;
}
```

**Config associee** (`config.inc.php`):
```php
$config['oidc_logout_url'] = 'https://auth.example.com/realms/REALM/protocol/openid-connect/logout?post_logout_redirect_uri=https%3A%2F%2Fmail.example.com&client_id=roundcube';
```

---

### 3. Auto-redirect vers OIDC (bypass page de login)

**Fichier:** `roundcube_oidc.php` (fonction `loginform`)

**Probleme:** Par defaut, l'utilisateur doit cliquer sur "Login with OIDC" sur la page de login Roundcube. Si OIDC est le seul mode d'authentification, cette etape est inutile.

**Modification:** Ajout d'une option `oidc_auto_redirect` qui redirige automatiquement vers le provider OIDC sans afficher la page de login.

```php
// Remplace le bloc de verification:
if (!isset($_GET['code']) && !isset($_GET['oidc'])) {
    $RCMAIL = rcmail::get_instance();
    if ($RCMAIL->config->get('oidc_auto_redirect', false)) {
        header('Location: ?oidc=1');
        exit;
    }
    $this->altReturn(null);
    return $content;
}
```

**Config associee** (`config.inc.php`):
```php
$config['oidc_auto_redirect'] = true;
```

---

## Nouvelles options de configuration

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `oidc_logout_url` | string | `''` | URL de deconnexion du provider OIDC |
| `oidc_auto_redirect` | bool | `false` | Redirection automatique vers OIDC sans afficher la page de login |

## Compatibilite

- Roundcube 1.6+
- PHP 8.x
- Teste avec Keycloak 25+ comme provider OIDC
- Serveur IMAP OVH (ssl0.ovh.net)
