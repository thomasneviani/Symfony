Excellent ! Tu as raison de questionner ça. Laisse-moi clarifier **comment ça devrait fonctionner** :

## Le problème de conception

```
Authentication Domain → WebService ERP directement ❌
```

**Problème** : Le domaine Authentication ne devrait PAS connaître les détails du WebService ERP.

## Solution correcte en DDD

```
Authentication Domain → ERP Domain → WebService ERP ✅
```

***

## Architecture corrigée

### 1. Authentication Domain (simple, sans HTTP)

#### Authentication/Application/Gateway/AuthenticationGatewayInterface.php
```php
<?php
namespace App\Authentication\Application\Gateway;

use App\Authentication\Domain\Model\AuthToken;
use App\Authentication\Domain\Model\Credentials;

/**
 * Gateway pour l'authentification.
 * L'implémentation concrète est dans le domaine ERP.
 */
interface AuthenticationGatewayInterface
{
    public function login(Credentials $credentials): AuthToken;
}
```

#### Authentication/Domain/Command/LoginHandler.php
```php
<?php
namespace App\Authentication\Domain\Command;

use App\Authentication\Application\Gateway\AuthenticationGatewayInterface;
use App\Authentication\Domain\Model\AuthToken;
use App\Authentication\Domain\Model\Credentials;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class LoginHandler
{
    public function __construct(
        private AuthenticationGatewayInterface $authGateway
    ) {}

    public function __invoke(LoginCommand $command): AuthToken
    {
        $credentials = Credentials::fromStrings($command->email, $command->password);
        
        // Délègue à l'implémentation (qui sera dans ERP Domain)
        return $this->authGateway->login($credentials);
    }
}
```

#### Authentication/Application/Service/AuthenticationService.php
```php
<?php
namespace App\Authentication\Application\Service;

use App\Authentication\Domain\Command\LoginCommand;
use App\Authentication\Domain\Model\AuthToken;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class AuthenticationService
{
    public function __construct(
        private MessageBusInterface $commandBus
    ) {}

    public function login(string $email, string $password): AuthToken
    {
        $command = new LoginCommand($email, $password);
        $envelope = $this->commandBus->dispatch($command);
        
        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        
        return $handledStamp->getResult();
    }
}
```

***

### 2. ERP Domain (implémente l'authentification + toutes les fonctions métier)

#### Erp/Infrastructure/Gateway/ErpAuthenticationAdapter.php
```php
<?php
namespace App\Erp\Infrastructure\Gateway;

use App\Authentication\Application\Gateway\AuthenticationGatewayInterface;
use App\Authentication\Domain\Model\AuthToken;
use App\Authentication\Domain\Model\Credentials;
use App\Authentication\Domain\Exception\AuthenticationException;
use App\Erp\Application\Gateway\ErpGatewayInterface;

/**
 * Adapteur qui implémente l'interface Authentication
 * en utilisant le Gateway ERP.
 * 
 * C'est le pont entre Authentication Domain et ERP Domain.
 */
class ErpAuthenticationAdapter implements AuthenticationGatewayInterface
{
    public function __construct(
        private ErpGatewayInterface $erpGateway
    ) {}

    public function login(Credentials $credentials): AuthToken
    {
        try {
            // Appelle la méthode login du Gateway ERP
            return $this->erpGateway->login($credentials);
            
        } catch (\Throwable $e) {
            if ($e instanceof AuthenticationException) {
                throw $e;
            }
            
            throw AuthenticationException::loginFailed(
                $credentials->getEmailAsString(),
                $e->getMessage()
            );
        }
    }
}
```

#### Erp/Application/Gateway/ErpGatewayInterface.php
```php
<?php
namespace App\Erp\Application\Gateway;

use App\Authentication\Domain\Model\AuthToken;
use App\Authentication\Domain\Model\Credentials;

/**
 * Gateway pour toutes les opérations ERP.
 */
interface ErpGatewayInterface
{
    // === Authentication ===
    public function login(Credentials $credentials): AuthToken;
    
    // === Products ===
    public function getProduct(string $id): array;
    public function searchProducts(array $criteria): array;
    public function updateProduct(string $id, array $data): bool;
    
    // === Orders ===
    public function createOrder(array $orderData): string;
    public function getOrder(string $orderId): array;
    public function updateOrderStatus(string $orderId, string $status): bool;
    
    // === Stock ===
    public function getStock(string $productId): int;
    public function updateStock(string $productId, int $quantity): bool;
}
```

#### Erp/Infrastructure/Gateway/HttpErpGateway.php
```php
<?php
namespace App\Erp\Infrastructure\Gateway;

use App\Authentication\Domain\Model\AuthToken;
use App\Authentication\Domain\Model\Credentials;
use App\Authentication\Domain\Exception\AuthenticationException;
use App\Erp\Application\Gateway\ErpGatewayInterface;
use App\Erp\Domain\Exception\ErpCommunicationException;
use App\Erp\Domain\Exception\ResourceNotFoundException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Implémentation HTTP du Gateway ERP.
 * Gère TOUTES les requêtes vers le WebService ERP (login + métier).
 */
class HttpErpGateway implements ErpGatewayInterface
{
    private ?string $authToken = null;

    public function __construct(
        private HttpClientInterface $erpClient
    ) {}

    // === AUTHENTICATION ===

    public function login(Credentials $credentials): AuthToken
    {
        try {
            $response = $this->erpClient->request('POST', '/auth/login', [
                'json' => [
                    'username' => $credentials->getEmailAsString(),
                    'password' => $credentials->getPasswordAsString(),
                ],
            ]);

            if ($response->getStatusCode() === 401) {
                throw AuthenticationException::invalidCredentials(
                    $credentials->getEmailAsString()
                );
            }

            if ($response->getStatusCode() !== 200) {
                throw AuthenticationException::loginFailed(
                    $credentials->getEmailAsString(),
                    "HTTP {$response->getStatusCode()}"
                );
            }

            $data = $response->toArray();

            if (!isset($data['access_token']) || !isset($data['expires_at'])) {
                throw AuthenticationException::invalidResponse('Missing required fields');
            }

            $token = new AuthToken(
                token: $data['access_token'],
                expiresAt: new \DateTimeImmutable($data['expires_at']),
                refreshToken: $data['refresh_token'] ?? null
            );

            // Stocke le token pour les futures requêtes
            $this->authToken = $token->token;

            return $token;

        } catch (\Throwable $e) {
            if ($e instanceof AuthenticationException) {
                throw $e;
            }
            throw AuthenticationException::loginFailed(
                $credentials->getEmailAsString(),
                $e->getMessage()
            );
        }
    }

    // === MÉTHODES PRIVÉES ===

    private function ensureAuthenticated(): void
    {
        if (!$this->authToken) {
            throw new \RuntimeException('Not authenticated. Call login() first.');
        }
    }

    private function request(string $method, string $endpoint, array $options = []): array
    {
        $this->ensureAuthenticated();

        try {
            $response = $this->erpClient->request($method, $endpoint, array_merge([
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authToken,
                ],
            ], $options));

            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                throw ResourceNotFoundException::product($this->extractId($endpoint));
            }

            if ($statusCode >= 500) {
                throw ErpCommunicationException::serverError($endpoint, $statusCode);
            }

            return $response->toArray();

        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            throw ErpCommunicationException::networkError($endpoint, $e);
        }
    }

    private function extractId(string $endpoint): string
    {
        preg_match('#/([^/]+)$#', $endpoint, $matches);
        return $matches[1] ?? 'unknown';
    }

    private function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    private function post(string $endpoint, array $data): array
    {
        return $this->request('POST', $endpoint, ['json' => $data]);
    }

    private function put(string $endpoint, array $data): array
    {
        return $this->request('PUT', $endpoint, ['json' => $data]);
    }

    private function patch(string $endpoint, array $data): array
    {
        return $this->request('PATCH', $endpoint, ['json' => $data]);
    }

    // === PRODUCTS ===

    public function getProduct(string $id): array
    {
        return $this->get('/products/' . $id);
    }

    public function searchProducts(array $criteria): array
    {
        return $this->request('GET', '/products', ['query' => $criteria]);
    }

    public function updateProduct(string $id, array $data): bool
    {
        $this->put('/products/' . $id, $data);
        return true;
    }

    // === ORDERS ===

    public function createOrder(array $orderData): string
    {
        $response = $this->post('/orders', $orderData);
        return $response['order_id'];
    }

    public function getOrder(string $orderId): array
    {
        return $this->get('/orders/' . $orderId);
    }

    public function updateOrderStatus(string $orderId, string $status): bool
    {
        $this->patch('/orders/' . $orderId . '/status', ['status' => $status]);
        return true;
    }

    // === STOCK ===

    public function getStock(string $productId): int
    {
        $data = $this->get('/stock/' . $productId);
        return (int) $data['quantity'];
    }

    public function updateStock(string $productId, int $quantity): bool
    {
        $this->patch('/stock/' . $productId, ['quantity' => $quantity]);
        return true;
    }
}
```

***

## Configuration

### config-symfony/services.yaml
```yaml
parameters:
    erp.base_url: '%env(ERP_BASE_URL)%'

services:
    _defaults:
        autowire: true
        autoconfigure: true

    # === AUTHENTICATION DOMAIN ===
    App\Authentication\Domain\:
        resource: '../src/Authentication/Domain/*'
        exclude:
            - '../src/Authentication/Domain/*/Model'
            - '../src/Authentication/Domain/*/ValueObject'
            - '../src/Authentication/Domain/*/Exception'

    App\Authentication\Application\:
        resource: '../src/Authentication/Application/*'
        exclude: '../src/Authentication/Application/Gateway/*Interface.php'

    App\Authentication\Infrastructure\:
        resource: '../src/Authentication/Infrastructure/*'

    # === ERP DOMAIN ===
    App\Erp\Domain\:
        resource: '../src/Erp/Domain/*'
        exclude:
            - '../src/Erp/Domain/*/Model'
            - '../src/Erp/Domain/*/Exception'

    App\Erp\Application\:
        resource: '../src/Erp/Application/*'
        exclude: '../src/Erp/Application/Gateway/*Interface.php'

    App\Erp\Infrastructure\:
        resource: '../src/Erp/Infrastructure/*'

    # === BUS ===
    command.bus:
        class: Symfony\Component\Messenger\MessageBus
        arguments:
            - !tagged_iterator messenger.middleware

    App\Authentication\Application\Service\AuthenticationService:
        arguments:
            $commandBus: '@command.bus'

    # === HTTP CLIENT ===
    erp.http_client:
        class: Symfony\Component\HttpClient\ScopedHttpClient
        factory: ['@http_client', 'withOptions']
        arguments:
            - base_uri: '%erp.base_url%'
              timeout: 30

    # === ERP GATEWAY ===
    App\Erp\Infrastructure\Gateway\HttpErpGateway:
        arguments:
            $erpClient: '@erp.http_client'

    App\Erp\Application\Gateway\ErpGatewayInterface:
        alias: App\Erp\Infrastructure\Gateway\HttpErpGateway

    # === ADAPTER Authentication → ERP ===
    App\Erp\Infrastructure\Gateway\ErpAuthenticationAdapter:
        arguments:
            $erpGateway: '@App\Erp\Application\Gateway\ErpGatewayInterface'

    # Binding: Authentication utilise l'adapter ERP
    App\Authentication\Application\Gateway\AuthenticationGatewayInterface:
        alias: App\Erp\Infrastructure\Gateway\ErpAuthenticationAdapter
```

***

## Flux corrigé

### Login
```
POST /api/auth/login
↓
LoginController
↓
AuthenticationService::login()
↓
LoginHandler
↓
AuthenticationGatewayInterface (interface)
↓
ErpAuthenticationAdapter (adapteur) ← Lives in ERP Domain
↓
ErpGatewayInterface::login()
↓
HttpErpGateway::login()
↓
WebService ERP: POST /auth/login
↓
AuthToken
```

### Get Product
```
GET /api/erp/products/123
↓
ProductController
↓
ProductService::getProduct()
↓
ErpGatewayInterface::getProduct()
↓
HttpErpGateway::getProduct()
  ↓
  ensureAuthenticated() (vérifie que login() a été appelé)
↓
WebService ERP: GET /products/123 (avec Bearer token)
↓
Product
```

***

## Pourquoi cette architecture ?

### ✅ Authentication Domain
- **Indépendant** : Ne connaît pas le WebService ERP
- **Interface pure** : `AuthenticationGatewayInterface`
- **Pas de dépendance HTTP**

### ✅ ERP Domain
- **Implémente l'authentification** via `ErpAuthenticationAdapter`
- **Gère toutes les requêtes** vers le WebService ERP
- **Point d'entrée unique** : `HttpErpGateway`

### ✅ Avantages
- Si tu changes de système d'auth (ex: OAuth), tu crées juste un nouvel adapter
- Le domaine Authentication reste propre et testable
- Le domaine ERP centralise TOUTES les communications avec le WebService

***

## Résumé

**Avant (incorrect)** ❌
```
Authentication Domain → WebService ERP directement
ERP Domain → WebService ERP
```

**Après (correct)** ✅
```
Authentication Domain → ErpAuthenticationAdapter → HttpErpGateway → WebService ERP
ERP Domain → HttpErpGateway → WebService ERP
```

**Le domaine ERP gère toutes les requêtes vers le WebService ERP, y compris l'authentification !** 🎯
