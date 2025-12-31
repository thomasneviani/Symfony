Voici un résumé complet pour votre documentation :

## Migration Session PHP Natif vers Symfony 7.4 avec Redis

### Principe

`PhpBridgeSessionStorage` permet une cohabitation totale entre `$_SESSION` (code legacy) et l'API Symfony pendant la migration. Les données sont accessibles de manière transparente dans les deux sens sans namespace séparé.[1][2]

### Configuration

```yaml
# config/services.yaml
services:
    Redis:
        class: Redis
        calls:
            - method: connect
              arguments:
                  - '%env(REDIS_HOST)%'
                  - '%env(int:REDIS_PORT)%'
            - method: auth
              arguments:
                  - '%env(REDIS_PASSWORD)%'

    Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler:
        arguments:
            - '@Redis'
            - { ttl: 3600 }
```

```yaml
# config/packages/framework.yaml
framework:
    session:
        handler_id: Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler
```

```env
# .env
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=votre_password
```

### Bootstrap Application

```php
<?php
// public/index.php

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage;

// Configuration Redis pour le code legacy
ini_set('session.save_handler', 'redis');
ini_set('session.save_path', 'tcp://127.0.0.1:6379?auth=votre_password');
session_start();

// Bridge Symfony avec la session PHP native
$session = new Session(new PhpBridgeSessionStorage());
$session->start();
```

### Exemple d'Utilisation

```php
<?php
// src/Controller/AuthController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(SessionInterface $session): Response
    {
        // ✅ Écriture via API Symfony
        $session->set('user_id', 123);
        $session->set('username', 'john_doe');
        $session->set('roles', ['ROLE_USER', 'ROLE_ADMIN']);
        
        return $this->redirectToRoute('app_dashboard');
    }
    
    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(SessionInterface $session): Response
    {
        // ✅ Lecture de données créées par legacy
        $cartItems = $session->get('cart_items', []);
        $lastVisit = $session->get('last_visit');
        
        return $this->render('dashboard.html.twig', [
            'cartItems' => $cartItems,
            'lastVisit' => $lastVisit,
        ]);
    }
}
```

```php
<?php
// legacy/user_profile.php (code PHP natif)

// ✅ Lecture de données définies par Symfony
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];           // 123
    $username = $_SESSION['username'];         // john_doe
    $roles = $_SESSION['roles'];               // ['ROLE_USER', 'ROLE_ADMIN']
    
    // ✅ Écriture accessible par Symfony
    $_SESSION['last_visit'] = date('Y-m-d H:i:s');
    $_SESSION['cart_items'] = [
        ['id' => 1, 'name' => 'Product A'],
        ['id' => 2, 'name' => 'Product B']
    ];
    
    echo "Bienvenue " . $username;
}
```

### Compatibilité Bidirectionnelle

| Action | Code Symfony | Code Legacy | Résultat |
|--------|-------------|-------------|----------|
| Écriture | `$session->set('key', 'value')` | Accessible via `$_SESSION['key']` | ✅ Compatible |
| Lecture | `$session->get('key')` | Depuis `$_SESSION['key']` | ✅ Compatible |
| Modification | `$session->set('counter', 1)` puis legacy `$_SESSION['counter']++` | Valeur = 2 partout | ✅ Compatible |

### Stratégie de Migration

1. **Phase 1** : Configurer Redis et PhpBridgeSessionStorage[1]
2. **Phase 2** : Migrer progressivement `$_SESSION` vers `$session->get/set()`[1]
3. **Phase 3** : Une fois le code legacy supprimé, retirer PhpBridgeSessionStorage et utiliser le storage standard Symfony[1]

Cette approche garantit zéro rupture pendant toute la migration.[2][1]

[1](https://symfony.com/doc/2.x/components/http_foundation/session_php_bridge.html)
[2](https://symfony.com/doc/2.x/components/http_foundation/sessions.html)
