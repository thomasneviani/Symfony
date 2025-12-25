# Architecture DDD complète : Auth + ERP

## Structure des fichiers

```
src/
├── Auth/
│   ├── Domain/
│   │   ├── Contract/
│   │   │   └── LoginUserInterface.php
│   │   └── Exception/
│   │       └── InvalidCredentialsException.php
│   ├── Application/
│   │   └── DTO/
│   │       ├── LoginInput.php
│   │       └── LoginOutput.php
│   └── Infrastructure/
│       └── ERP/
│           └── ErpLoginUserAdapter.php
│
└── ERP/
    ├── Domain/
    │   ├── Contract/
    │   │   └── ErpAuthenticateUserInterface.php
    │   └── Exception/
    │       └── ErpInvalidCredentialsException.php
    ├── Application/
    │   └── DTO/
    │       └── ErpAuthResult.php
    └── Infrastructure/
        └── Http/
            └── ErpAuthenticateUser.php
```

***

## Fichiers du contexte ERP

### 1. Port du domaine ERP
```php
// src/ERP/Domain/Contract/ErpAuthenticateUserInterface.php
<?php

namespace App\ERP\Domain\Contract;

use App\ERP\Application\DTO\ErpAuthResult;

interface ErpAuthenticateUserInterface
{
    /**
     * Authentifie un utilisateur via l'ERP externe.
     *
     * @throws \App\ERP\Domain\Exception\ErpInvalidCredentialsException
     */
    public function authenticate(string $email, string $password): ErpAuthResult;
}
```

### 4. Adapter HTTP vers l'ERP externe
```php
// src/ERP/Infrastructure/Http/ErpAuthenticateUser.php
<?php

namespace App\ERP\Infrastructure\Http;

use App\ERP\Domain\Contract\ErpAuthenticateUserInterface;
use App\ERP\Domain\Exception\ErpInvalidCredentialsException;
use App\ERP\Application\DTO\ErpAuthResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

final readonly class ErpAuthenticateUser implements ErpAuthenticateUserInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $erpBaseUrl,
        private string $apiKey,
    ) {}

    public function authenticate(string $email, string $password): ErpAuthResult
    {
        try {
            $response = $this->httpClient->request('POST', $this->erpBaseUrl . '/auth/login', [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'json' => ['email' => $email, 'password' => $password],
            ]);

            $data = $response->toArray(false);
        } catch (ClientExceptionInterface $e) {
            throw new ErpInvalidCredentialsException();
        }

        return new ErpAuthResult(
            accessToken: $data['access_token'],
            expiresIn: $data['expires_in'],
            refreshToken: $data['refresh_token'] ?? null
        );
    }
}
```

***

## Fichiers du contexte Auth

### 5. Port du domaine Auth
```php
// src/Auth/Domain/Contract/LoginUserInterface.php
<?php

namespace App\Auth\Domain\Contract;

use App\Auth\Application\DTO\LoginInput;
use App\Auth\Application\DTO\LoginOutput;

interface LoginUserInterface
{
    /**
     * Authentifie un utilisateur.
     *
     * @throws \App\Auth\Domain\Exception\InvalidCredentialsException
     */
    public function login(LoginInput $input): LoginOutput;
}
```

### 8. Anti-Corruption Layer (Adapter)
```php
// src/Auth/Infrastructure/ERP/ErpLoginUserAdapter.php
<?php

namespace App\Auth\Infrastructure\ERP;

use App\Auth\Application\DTO\LoginInput;
use App\Auth\Application\DTO\LoginOutput;
use App\Auth\Domain\Contract\LoginUserInterface;
use App\Auth\Domain\Exception\InvalidCredentialsException;
use App\ERP\Domain\Contract\ErpAuthenticateUserInterface;
use App\ERP\Domain\Exception\ErpInvalidCredentialsException;

final readonly class ErpLoginUserAdapter implements LoginUserInterface
{
    public function __construct(
        private ErpAuthenticateUserInterface $erpAuthenticateUser
    ) {}

    public function login(LoginInput $input): LoginOutput
    {
        try {
            $result = $this->erpAuthenticateUser->authenticate(
                $input->email,
                $input->password
            );
        } catch (ErpInvalidCredentialsException) {
            throw new InvalidCredentialsException();
        }

        return new LoginOutput(
            accessToken: $result->accessToken,
            expiresIn: $result->expiresIn,
            refreshToken: $result->refreshToken
        );
    }
}
```

***

## Configuration Symfony

```yaml
# config/services.yaml
services:
    # Configuration ERP
    App\ERP\Domain\Contract\ErpAuthenticateUserInterface:
        class: App\ERP\Infrastructure\Http\ErpAuthenticateUser
        arguments:
            $httpClient: '@http_client'
            $erpBaseUrl: '%env(ERP_BASE_URL)%'
            $apiKey: '%env(ERP_API_KEY)%'
    
    # Configuration Auth - binding vers l'adapter ERP
    App\Auth\Domain\Contract\LoginUserInterface:
        class: App\Auth\Infrastructure\ERP\ErpLoginUserAdapter
```

***

## Utilisation dans un contrôleur

```php
// src/Auth/Infrastructure/Controller/LoginController.php
<?php

namespace App\Auth\Infrastructure\Controller;

use App\Auth\Application\DTO\LoginInput;
use App\Auth\Domain\Contract\LoginUserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LoginController
{
    public function __construct(
        private LoginUserInterface $loginUser
    ) {}

    #[Route('/api/login', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $input = new LoginInput(
            email: $data['email'],
            password: $data['password']
        );
        
        $output = $this->loginUser->login($input);
        
        return new JsonResponse([
            'access_token' => $output->accessToken,
            'expires_in' => $output->expiresIn,
            'refresh_token' => $output->refreshToken,
        ]);
    }
}
```
