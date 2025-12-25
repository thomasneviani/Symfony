Parfait.
Voici **l’exemple FINAL, complet, cohérent et réaliste**, avec **les dossiers + le code clé**, exactement pour **TON cas** :
👉 2 appels API
👉 3 services DB
👉 validation `error === 0`
👉 handler = orchestration
👉 zéro over-engineering

---

# 🗂️ Architecture finale (à copier telle quelle)

```text
src/
├── Application/
│   └── Auth/
│       ├── Command/
│       │   └── LoginErpCommand.php
│       ├── Handler/
│       │   └── LoginErpHandler.php
│       ├── Service/
│       │   ├── UserFinderService.php
│       │   ├── UserPermissionService.php
│       │   └── TokenGenerator.php
│       └── Exception/
│           └── ErpAuthenticationFailedException.php
│
├── Domain/
│   ├── User/
│   │   ├── User.php
│   │   └── UserRepository.php
│   └── Auth/
│       └── ValueObject/
│           └── AuthToken.php
│
├── Infrastructure/
│   ├── Erp/
│   │   ├── Client/
│   │   │   ├── ErpAuthClient.php
│   │   │   └── ErpProfileClient.php
│   │   └── DTO/
│   │       ├── ErpLoginResponse.php
│   │       └── ErpProfileResponse.php
│   └── Doctrine/
│       └── UserRepository.php
│
└── UI/
    └── Http/
        └── Controller/
            └── LoginErpController.php
```

---

# 1️⃣ Command

```php
// src/Application/Auth/Command/LoginErpCommand.php
final class LoginErpCommand
{
    public function __construct(
        public readonly string $login,
        public readonly string $password
    ) {}
}
```

---

# 2️⃣ DTO ERP

```php
// src/Infrastructure/Erp/DTO/ErpLoginResponse.php
final class ErpLoginResponse
{
    public function __construct(
        public readonly int $error,
        public readonly ?string $token
    ) {}
}
```

```php
// src/Infrastructure/Erp/DTO/ErpProfileResponse.php
final class ErpProfileResponse
{
    public function __construct(
        public readonly string $erpId,
        public readonly string $email
    ) {}
}
```

---

# 3️⃣ Clients ERP (Infrastructure pure)

```php
// src/Infrastructure/Erp/Client/ErpAuthClient.php
final class ErpAuthClient
{
    public function login(string $login, string $password): ErpLoginResponse
    {
        // appel HTTP ERP
        // $data = ...

        return new ErpLoginResponse(
            error: $data['error'],
            token: $data['token'] ?? null
        );
    }
}
```

```php
// src/Infrastructure/Erp/Client/ErpProfileClient.php
final class ErpProfileClient
{
    public function fetchProfile(string $token): ErpProfileResponse
    {
        // appel HTTP ERP
        // $data = ...

        return new ErpProfileResponse(
            erpId: $data['id'],
            email: $data['email']
        );
    }
}
```

---

# 4️⃣ Services applicatifs (DB / règles locales)

```php
// src/Application/Auth/Service/UserFinderService.php
final class UserFinderService
{
    public function __construct(
        private UserRepository $userRepository
    ) {}

    public function findByErpId(string $erpId): User
    {
        return $this->userRepository->findByErpId($erpId);
    }
}
```

```php
// src/Application/Auth/Service/UserPermissionService.php
final class UserPermissionService
{
    public function forUser(User $user): array
    {
        return $user->permissions();
    }
}
```

```php
// src/Application/Auth/Service/TokenGenerator.php
final class TokenGenerator
{
    public function generate(User $user, array $permissions): AuthToken
    {
        // génération JWT ou autre
        return new AuthToken('jwt-token-value');
    }
}
```

---

# 5️⃣ Value Object domaine

```php
// src/Domain/Auth/ValueObject/AuthToken.php
final class AuthToken
{
    public function __construct(
        private string $value
    ) {}

    public function value(): string
    {
        return $this->value;
    }
}
```

---

# 6️⃣ Exception applicative

```php
// src/Application/Auth/Exception/ErpAuthenticationFailedException.php
final class ErpAuthenticationFailedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('ERP authentication failed');
    }
}
```

---

# 7️⃣ 🎯 LE HANDLER FINAL (le cœur)

```php
// src/Application/Auth/Handler/LoginErpHandler.php
final class LoginErpHandler
{
    public function __construct(
        private ErpAuthClient $erpAuthClient,
        private ErpProfileClient $erpProfileClient,
        private UserFinderService $userFinder,
        private UserPermissionService $permissionService,
        private TokenGenerator $tokenGenerator
    ) {}

    public function __invoke(LoginErpCommand $command): AuthToken
    {
        // 1️⃣ Auth ERP
        $loginResponse = $this->erpAuthClient->login(
            $command->login,
            $command->password
        );

        // ✅ validation ICI
        if ($loginResponse->error !== 0 || $loginResponse->token === null) {
            throw new ErpAuthenticationFailedException();
        }

        // 2️⃣ Profil ERP
        $profile = $this->erpProfileClient->fetchProfile(
            $loginResponse->token
        );

        // 3️⃣ User local
        $user = $this->userFinder->findByErpId($profile->erpId);

        // 4️⃣ Permissions
        $permissions = $this->permissionService->forUser($user);

        // 5️⃣ Token final
        return $this->tokenGenerator->generate($user, $permissions);
    }
}
```

---

# 8️⃣ Controller (UI = traduction HTTP)

```php
// src/UI/Http/Controller/LoginErpController.php
final class LoginErpController extends AbstractController
{
    public function __construct(
        private LoginErpHandler $handler
    ) {}

    #[Route('/api/login/erp', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $token = ($this->handler)(new LoginErpCommand(
                $request->get('login'),
                $request->get('password')
            ));

            return $this->json(['token' => $token->value()]);
        } catch (ErpAuthenticationFailedException) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }
    }
}
```

---

## 🧠 Règle finale (à garder pour tout le projet)

* **Handler** → scénario / ordre / décisions
* **Service applicatif** → travail ciblé et réutilisable
* **Client** → API externe
* **Domain** → objets métiers purs
* **Controller** → HTTP only

👉 Ce que tu as là est **100 % légit**, **maintenable**, **testable**, et **défendable en entretien ou en code review**.

Si tu veux, prochain step possible :

* test unitaire complet du handler
* version avec transaction DB
* version async (Messenger)
* ou audit de TON code réel

Tu choisis.
