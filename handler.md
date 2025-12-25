Parfait, **là on touche un cas réel**, pas un exemple de tuto 👍
Réponse courte : **oui, c’est normal**, et **ça ne change pas la règle**.
Réponse structurée juste après.

---

## 🎯 Situation réelle (la tienne)

> 👉 *“j’ai 2 appels API externes + 3 services qui vont chercher de la data en DB”*

➡ **C’est exactement un use case applicatif**
➡ Donc **1 handler**, qui orchestre **plusieurs dépendances**

---

## 🧠 Principe clé (à ne pas lâcher)

> **Le handler orchestre, il ne travaille pas.**
> **Les services travaillent, ils ne décident pas du scénario.**

---

## 🧩 Architecture adaptée à ton cas

```text
LoginErpHandler
 ├── ErpAuthClient
 ├── ErpProfileClient
 ├── UserFinderService
 ├── UserPermissionService
 └── TokenGenerator
```

➡ **2 appels API**
➡ **3 services DB**
➡ **1 scénario clair**

---

## 🗂️ Arborescence réaliste

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
│   │
│   └── Doctrine/
│       └── UserRepository.php
│
└── UI/
    └── Http/
        └── Controller/
            └── LoginErpController.php
```

---

## 🧠 Le flux (important)

```text
1. Login ERP (API #1)
2. Validation error === 0
3. Récupération profil ERP (API #2)
4. Récupération user local
5. Récupération droits / rôles
6. Génération token
```

➡ **le handler sait l’ordre**
➡ **les services ne le savent pas**

---

## 🎯 Exemple de Handler réaliste

```php
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

✔ clair
✔ linéaire
✔ lisible
✔ testable

---

## 🧪 Et les tests ? (important)

Tu peux tester :

* **le handler avec des fakes**
* **chaque service séparément**

```php
public function test_login_fails_when_erp_auth_fails()
{
    $erpClient = new FakeErpAuthClient(error: 1);

    $handler = new LoginErpHandler(
        $erpClient,
        new FakeErpProfileClient(),
        new FakeUserFinder(),
        new FakePermissionService(),
        new FakeTokenGenerator()
    );

    $this->expectException(ErpAuthenticationFailedException::class);

    $handler(new LoginErpCommand('john', 'bad-pass'));
}
```

---

## 🚨 Quand s’inquiéter ?

### ❌ Handler trop gros ( > ~50 lignes )

➡ découpe en **services applicatifs**

### ❌ Services qui appellent d’autres services

➡ fuite d’orchestration

### ❌ Service qui dépend de l’ordre

➡ responsabilité mal placée

---

## 🧠 Règle finale à retenir (la plus importante)

> **Le handler connaît le scénario.
> Les services connaissent leur métier.
> Les clients connaissent les APIs.**

---

Si tu veux, prochaine étape :

* on **refactor ton cas réel**
* je te fais une **version avec transactions**
* ou une **version async (Messenger)**

Dis-moi 👌
