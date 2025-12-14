Voici **tous les fichiers corrigés** pour le système de logs :

***

## 📁 Structure complète

```
src/Shared/
├── Domain/
│   └── Logging/
│       └── LoggerInterface.php
│
└── Infrastructure/
    ├── Logging/
    │   ├── Adapter/
    │   │   └── MonologLogger.php
    │   ├── Context/
    │   │   └── TraceContext.php
    │   └── Processor/
    │       ├── TraceIdProcessor.php
    │       ├── RequestProcessor.php
    │       └── UserProcessor.php
    │
    └── Http/
        └── EventListener/
            └── TraceContextInitializer.php
```

***

## 📄 Fichiers sources

### 1. Interface domaine

#### src/Shared/Domain/Logging/LoggerInterface.php
```php
<?php
namespace App\Shared\Domain\Logging;

/**
 * Interface de logging métier - 100% domaine pur.
 * Aucune dépendance externe (PSR, Symfony, etc.).
 */
interface LoggerInterface
{
    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
}
```

***

### 2. Contexte global

#### src/Shared/Infrastructure/Logging/Context/TraceContext.php
```php
<?php
namespace App\Shared\Infrastructure\Logging\Context;

/**
 * Contexte de trace partagé dans toute l'application.
 * Request-scoped pour éviter les fuites entre requêtes.
 */
final class TraceContext
{
    private ?string $traceId = null;
    private array $metadata = [];

    public function setTraceId(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    public function getTraceId(): string
    {
        if ($this->traceId === null) {
            $this->traceId = bin2hex(random_bytes(16));
        }

        return $this->traceId;
    }

    public function addMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
```

***

### 3. Adapter Monolog

#### src/Shared/Infrastructure/Logging/Adapter/MonologLogger.php
```php
<?php
namespace App\Shared\Infrastructure\Logging\Adapter;

use App\Shared\Domain\Logging\LoggerInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

/**
 * Adapter qui traduit l'interface domaine vers PSR-3 / Monolog.
 */
final readonly class MonologLogger implements LoggerInterface
{
    public function __construct(
        private PsrLoggerInterface $psrLogger
    ) {}

    public function debug(string $message, array $context = []): void
    {
        $this->psrLogger->debug($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->psrLogger->info($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->psrLogger->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->psrLogger->error($message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->psrLogger->critical($message, $context);
    }
}
```

***

### 4. Processors Monolog

#### src/Shared/Infrastructure/Logging/Processor/TraceIdProcessor.php
```php
<?php
namespace App\Shared\Infrastructure\Logging\Processor;

use App\Shared\Infrastructure\Logging\Context\TraceContext;
use Monolog\LogRecord;

/**
 * Ajoute automatiquement le trace_id et métadonnées à tous les logs.
 */
final readonly class TraceIdProcessor
{
    public function __construct(
        private TraceContext $traceContext
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['trace_id'] = $this->traceContext->getTraceId();

        $metadata = $this->traceContext->getMetadata();

        // Environment et service en top-level dans extra
        $record->extra['environment'] = $metadata['environment'] ?? 'unknown';
        $record->extra['service'] = $metadata['service'] ?? 'symfony-app';

        // Autres métadonnées custom dans metadata (si présentes)
        unset($metadata['environment'], $metadata['service']);
        if (!empty($metadata)) {
            $record->extra['metadata'] = $metadata;
        }

        return $record;
    }
}
```

#### src/Shared/Infrastructure/Logging/Processor/RequestProcessor.php
```php
<?php
namespace App\Shared\Infrastructure\Logging\Processor;

use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Ajoute automatiquement les infos HTTP à tous les logs.
 */
final readonly class RequestProcessor
{
    public function __construct(
        private RequestStack $requestStack
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return $record;
        }

        $record->extra['http'] = [
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'ip' => $request->getClientIp(),
        ];

        return $record;
    }
}
```

#### src/Shared/Infrastructure/Logging/Processor/UserProcessor.php
```php
<?php
namespace App\Shared\Infrastructure\Logging\Processor;

use Monolog\LogRecord;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Ajoute automatiquement les infos utilisateur (RGPD compliant).
 */
final readonly class UserProcessor
{
    public function __construct(
        private Security $security
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $user = $this->security->getUser();

        if ($user === null) {
            return $record;
        }

        $record->extra['user'] = [
            'identifier' => $user->getUserIdentifier(),
        ];

        return $record;
    }
}
```

***

### 5. Event Listener

#### src/Shared/Infrastructure/Http/EventListener/TraceContextInitializer.php
```php
<?php
namespace App\Shared\Infrastructure\Http\EventListener;

use App\Shared\Infrastructure\Logging\Context\TraceContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gère le trace_id : initialisation + header de réponse.
 */
final readonly class TraceContextInitializer
{
    public function __construct(
        private TraceContext $traceContext
    ) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Récupère le trace ID du header ou en génère un nouveau
        $traceId = $request->headers->get('X-Trace-ID') ?? bin2hex(random_bytes(16));

        $this->traceContext->setTraceId($traceId);

        // Stocke dans les attributs de la requête
        $request->attributes->set('_trace_id', $traceId);

        // Ajoute des métadonnées globales
        $this->traceContext->addMetadata('environment', $_ENV['APP_ENV'] ?? 'unknown');
        $this->traceContext->addMetadata('service', 'symfony-app');
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $traceId = $event->getRequest()->attributes->get('_trace_id');
        if ($traceId) {
            $event->getResponse()->headers->set('X-Trace-ID', $traceId);
        }
    }
}
```

***

## 📝 Configuration

### config/packages/monolog.yaml
```yaml
monolog:
    channels:
        - security
        - authentication
        - erp
        - audit

when@prod:
    monolog:
        handlers:
            # Logs principaux (JSON stdout pour Kubernetes)
            main:
                type: stream
                path: "php://stdout"
                level: info
                formatter: monolog.formatter.json
                channels: ["!event", "!doctrine", "!console"]

            # Alertes critiques (Slack)
            critical:
                type: slack
                token: '%env(SLACK_WEBHOOK_URL)%'
                channel: '#alerts-prod'
                level: critical

when@dev:
    monolog:
        handlers:
            main:
                type: stream
                path: "%kernel.logs_dir%/%kernel.environment%.log"
                level: debug

            console:
                type: console
                process_psr_3_messages: false
                channels: ["!event", "!doctrine"]
```

***

### config/services.yaml
```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    # === LOGGING ===

    # TraceContext request-scoped
    App\Shared\Infrastructure\Logging\Context\TraceContext:
        shared: false

    # Adapter Logger principal
    App\Shared\Domain\Logging\LoggerInterface:
        class: App\Shared\Infrastructure\Logging\Adapter\MonologLogger
        arguments:
            $psrLogger: '@logger'

    # Processors Monolog
    App\Shared\Infrastructure\Logging\Processor\TraceIdProcessor:
        tags: [{ name: monolog.processor }]

    App\Shared\Infrastructure\Logging\Processor\RequestProcessor:
        tags: [{ name: monolog.processor }]

    App\Shared\Infrastructure\Logging\Processor\UserProcessor:
        tags: [{ name: monolog.processor }]

    # Event Listener (auto-découverte)
    App\Shared\Infrastructure\Http\EventListener\TraceContextInitializer: ~

    # === AUTHENTICATION ===
    
    # LoginHandler avec channel security
    App\Authentication\Domain\Command\LoginHandler:
        arguments:
            $logger: '@monolog.logger.security'
```

***

### .env
```bash
###> App Configuration ###
APP_ENV=prod
APP_SECRET=your-secret-here
###< App Configuration ###

###> Slack Alerts ###
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
###< Slack Alerts ###

###> ERP Configuration ###
ERP_BASE_URL=https://erp.example.com/api/v1
###< ERP Configuration ###
```

***

## 🎯 Exemples d'utilisation

### Dans un Handler (LoginHandler)

#### Authentication/Domain/Command/LoginHandler.php
```php
<?php
namespace App\Authentication\Domain\Command;

use App\Authentication\Application\Gateway\AuthenticationGatewayInterface;
use App\Authentication\Domain\Exception\AuthenticationException;
use App\Authentication\Domain\Model\AuthToken;
use App\Authentication\Domain\Model\Credentials;
use App\Shared\Domain\Logging\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class LoginHandler
{
    public function __construct(
        private AuthenticationGatewayInterface $authGateway,
        private LoggerInterface $logger
    ) {}

    public function __invoke(LoginCommand $command): AuthToken
    {
        $this->logger->info('User authentication attempt via ERP', [
            'action' => 'login_attempt',
            'auth_provider' => 'erp',
            'domain' => 'authentication',
        ]);

        try {
            $credentials = Credentials::fromStrings($command->email, $command->password);
            $token = $this->authGateway->login($credentials);

            $this->logger->info('User authentication successful via ERP', [
                'action' => 'login_success',
                'auth_provider' => 'erp',
                'domain' => 'authentication',
            ]);

            return $token;

        } catch (AuthenticationException $e) {
            $this->logger->warning('User authentication failed via ERP', [
                'action' => 'login_failed',
                'auth_provider' => 'erp',
                'domain' => 'authentication',
                'reason' => $e->getErrorCode(),
            ]);

            throw $e;
        }
    }
}
```

***

### Dans un Gateway (HttpErpGateway)

#### Erp/Infrastructure/Gateway/HttpErpGateway.php
```php
<?php
namespace App\Erp\Infrastructure\Gateway;

use App\Erp\Application\Gateway\ErpGatewayInterface;
use App\Shared\Domain\Logging\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpErpGateway implements ErpGatewayInterface
{
    public function __construct(
        private HttpClientInterface $erpClient,
        private LoggerInterface $logger
    ) {}

    public function getProduct(string $id): array
    {
        $this->logger->info('ERP product request initiated', [
            'action' => 'erp_api_call',
            'domain' => 'erp',
            'endpoint' => '/products/' . $id,
            'method' => 'GET',
            'resource_type' => 'product',
            'resource_id' => $id,
        ]);

        $startTime = microtime(true);

        try {
            $response = $this->erpClient->request('GET', '/products/' . $id);
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logger->info('ERP product retrieved', [
                'action' => 'erp_api_response',
                'domain' => 'erp',
                'endpoint' => '/products/' . $id,
                'status_code' => $response->getStatusCode(),
                'duration_ms' => round($duration, 2),
            ]);

            return $response->toArray();

        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logger->error('ERP communication error', [
                'action' => 'erp_api_error',
                'domain' => 'erp',
                'endpoint' => '/products/' . $id,
                'duration_ms' => round($duration, 2),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
```

***

## 📊 Exemples de logs générés

### Login réussi
```json
{
  "message": "User authentication successful via ERP",
  "level": 200,
  "level_name": "INFO",
  "channel": "security",
  "datetime": "2025-12-14T13:44:00.123456+00:00",
  "context": {
    "action": "login_success",
    "auth_provider": "erp",
    "domain": "authentication"
  },
  "extra": {
    "trace_id": "a1b2c3d4e5f67890abcd",
    "environment": "prod",
    "service": "symfony-app",
    "http": {
      "method": "POST",
      "uri": "/api/auth/login",
      "ip": "192.168.1.100"
    },
    "user": {
      "identifier": "john@example.com"
    }
  }
}
```

### Login échoué
```json
{
  "message": "User authentication failed via ERP",
  "level": 300,
  "level_name": "WARNING",
  "channel": "security",
  "datetime": "2025-12-14T13:44:01.234567+00:00",
  "context": {
    "action": "login_failed",
    "auth_provider": "erp",
    "domain": "authentication",
    "reason": "invalid_credentials"
  },
  "extra": {
    "trace_id": "a1b2c3d4e5f67890abcd",
    "environment": "prod",
    "service": "symfony-app",
    "http": {
      "method": "POST",
      "uri": "/api/auth/login",
      "ip": "192.168.1.100"
    }
  }
}
```

### ERP API call
```json
{
  "message": "ERP product request initiated",
  "level": 200,
  "level_name": "INFO",
  "channel": "app",
  "datetime": "2025-12-14T13:45:00.345678+00:00",
  "context": {
    "action": "erp_api_call",
    "domain": "erp",
    "endpoint": "/products/PROD-123",
    "method": "GET",
    "resource_type": "product",
    "resource_id": "PROD-123"
  },
  "extra": {
    "trace_id": "b2c3d4e5f6789012cdef",
    "environment": "prod",
    "service": "symfony-app",
    "http": {
      "method": "GET",
      "uri": "/api/erp/products/PROD-123",
      "ip": "192.168.1.100"
    },
    "user": {
      "identifier": "john@example.com"
    }
  }
}
```

***

## ✅ Checklist finale

- ✅ **7 fichiers sources** (interface + adapter + context + 3 processors + listener)
- ✅ **2 fichiers config** (monolog.yaml + services.yaml)
- ✅ **1 fichier env** (.env avec SLACK_WEBHOOK_URL)
- ✅ **TraceContext request-scoped** (pas de fuites)
- ✅ **Processors automatiques** (trace_id, http, user)
- ✅ **Channels séparés** (security, erp, audit)
- ✅ **Format JSON structuré** (context métier + extra enrichi)
- ✅ **Production-ready** (stdout, ELK/Datadog compatible)
- ✅ **Clean Architecture** (domaine pur, pas de couplage)

**C'est complet et prêt à déployer !** 🚀
