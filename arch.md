# Architecture DDD finale : Auth + ERP (essentiel uniquement)

## Structure minimale

```
src/
├── Auth/
│   ├── Domain/
│   │   ├── Port/
│   │   │   ├── LoginUserInterface.php
│   │   │   └── ExternalAuthPort.php
│   │   └── Service/
│   │       └── LoginService.php
│   └── Infrastructure/
│       └── Adapter/
│           └── ErpAuthAdapter.php
│
└── ERP/
    ├── Domain/
    │   └── Port/
    │       └── ErpAuthenticationPort.php
    └── Infrastructure/
        └── Adapter/
            └── ErpHttpAuthAdapter.php
```

***

## Contexte ERP

### 1. Port du domaine ERP

```php
// src/ERP/Domain/Port/ErpAuthenticationPort.php
<?php

namespace App\ERP\Domain\Port;

interface ErpAuthenticationPort
{
    public function authenticate(string $email, string $password): array;
}
```

### 2. Adapter HTTP ERP

```php
// src/ERP/Infrastructure/Adapter/ErpHttpAuthAdapter.php
<?php

namespace App\ERP\Infrastructure\Adapter;

use App\ERP\Domain\Port\ErpAuthenticationPort;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ErpHttpAuthAdapter implements ErpAuthenticationPort
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $erpBaseUrl,
        private string $apiKey,
    ) {}

    public function authenticate(string $email, string $password): array
    {
        $response = $this->httpClient->request('POST', $this->erpBaseUrl . '/auth/login', [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
            'json' => ['email' => $email, 'password' => $password],
        ]);

        return $response->toArray();
    }
}
```

***

## Contexte Auth

### 3. Port primaire Auth

```php
// src/Auth/Domain/Port/LoginUserInterface.php
<?php

namespace App\Auth\Domain\Port;

interface LoginUserInterface
{
    public function login(string $email, string $password): array;
}
```

### 4. Port secondaire Auth (définit le besoin)

```php
// src/Auth/Domain/Port/ExternalAuthPort.php
<?php

namespace App\Auth\Domain\Port;

/**
 * Auth définit ce dont il a besoin
 * Sans connaître l'implémentation (ERP, LDAP, OAuth...)
 */
interface ExternalAuthPort
{
    public function authenticate(string $email, string $password): array;
}
```

### 5. Service du domaine Auth

```php
// src/Auth/Domain/Service/LoginService.php
<?php

namespace App\Auth\Domain\Service;

use App\Auth\Domain\Port\LoginUserInterface;
use App\Auth\Domain\Port\ExternalAuthPort;

/**
 * Logique métier Auth
 * Dépend UNIQUEMENT de son propre port
 */
final readonly class LoginService implements LoginUserInterface
{
    public function __construct(
        private ExternalAuthPort $authPort
    ) {}

    public function login(string $email, string $password): array
    {
        return $this->authPort->authenticate($email, $password);
    }
}
```

### 6. Adapter Auth → ERP (Anti-Corruption Layer)

```php
// src/Auth/Infrastructure/Adapter/ErpAuthAdapter.php
<?php

namespace App\Auth\Infrastructure\Adapter;

use App\Auth\Domain\Port\ExternalAuthPort;
use App\ERP\Domain\Port\ErpAuthenticationPort;

/**
 * ACL : fait le pont entre Auth et ERP
 */
final readonly class ErpAuthAdapter implements ExternalAuthPort
{
    public function __construct(
        private ErpAuthenticationPort $erpAuthPort
    ) {}

    public function authenticate(string $email, string $password): array
    {
        return $this->erpAuthPort->authenticate($email, $password);
    }
}
```

***

## Configuration Symfony

```yaml
# config/services.yaml
services:
    # Port ERP → Adapter HTTP
    App\ERP\Domain\Port\ErpAuthenticationPort:
        class: App\ERP\Infrastructure\Adapter\ErpHttpAuthAdapter
        arguments:
            $httpClient: '@http_client'
            $erpBaseUrl: '%env(ERP_BASE_URL)%'
            $apiKey: '%env(ERP_API_KEY)%'

    # Port secondaire Auth → Adapter ERP (ACL)
    App\Auth\Domain\Port\ExternalAuthPort:
        class: App\Auth\Infrastructure\Adapter\ErpAuthAdapter

    # Port primaire Auth → Service métier
    App\Auth\Domain\Port\LoginUserInterface:
        class: App\Auth\Domain\Service\LoginService
```

```env
# .env
ERP_BASE_URL=https://api.erp-externe.com
ERP_API_KEY=your_api_key
```

***

## Flux de dépendances

```
Controller
    ↓
LoginUserInterface (Auth)
    ↓
LoginService (Auth Domain)
    ↓
ExternalAuthPort (Auth - ne connaît PAS l'ERP)
    ↓
ErpAuthAdapter (Auth Infrastructure - ACL)
    ↓
ErpAuthenticationPort (ERP)
    ↓
ErpHttpAuthAdapter (ERP Infrastructure)
    ↓
API ERP Externe
```

***

## Principes respectés

- **Auth ne connaît pas l'ERP** : le domaine Auth dépend uniquement de `ExternalAuthPort`[1][2]
- **Dependency Inversion** : Auth définit son besoin, l'infrastructure fournit l'implémentation[3]
- **Anti-Corruption Layer** : `ErpAuthAdapter` isole Auth des changements de l'ERP[2][4]
- **Ports & Adapters** : les domaines dépendent d'interfaces, pas d'implémentations concrètes[3]

Le domaine Auth est **totalement découplé** de l'ERP. Remplacer l'ERP par LDAP nécessite uniquement un nouveau `LdapAuthAdapter` - zéro modification dans le domaine Auth.[4][2]

[1](https://www.martinfowler.com/bliki/BoundedContext.html)
[2](https://ddd-practitioners.com/home/glossary/bounded-context/bounded-context-relationship/anticorruption-layer/)
[3](https://scalastic.io/en/hexagonal-architecture/)
[4](https://buildsimple.substack.com/p/strategic-ddd-the-shield-of-anti)
