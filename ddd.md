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

Voici les fichiers complets et corrigés pour ton système d'exceptions métier avec traduction en DDD/Clean Arch.[1][2]

## AbstractAuthException.php

```php
<?php

declare(strict_types=1);

namespace Acme\Domain\Auth\Exception;

use RuntimeException;

abstract class AbstractAuthException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $errorContext = [];
    
    protected string $errorCode;

    protected function __construct(
        string $errorCode,
        string $messageKey,
        array $errorContext = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($messageKey, 0, $previous);
        $this->errorCode = $errorCode;
        $this->errorContext = $errorContext;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function errorContext(): array
    {
        return $this->errorContext;
    }

    public function messageKey(): string
    {
        return $this->getMessage();
    }

    public function translationDomain(): string
    {
        return 'auth';
    }
}
```

## AuthExceptions.php

```php
<?php

declare(strict_types=1);

namespace Acme\Domain\Auth\Exception;

final class AuthExceptions extends AbstractAuthException
{
    public static function invalidCredentials(string $username): self
    {
        return new self(
            'AUTH_001',
            'auth.invalid_credentials',
            ['%username%' => $username]
        );
    }

    public static function accountLocked(string $userId, \DateTimeInterface $until): self
    {
        return new self(
            'AUTH_002',
            'auth.account_locked',
            [
                '%user_id%' => $userId,
                '%until%' => $until->format('d/m/Y à H:i'),
            ]
        );
    }

    public static function tokenExpired(): self
    {
        return new self(
            'AUTH_003',
            'auth.token_expired'
        );
    }

    public static function insufficientPermissions(string $resource): self
    {
        return new self(
            'AUTH_004',
            'auth.insufficient_permissions',
            ['%resource%' => $resource]
        );
    }

    public static function userNotFound(string $identifier): self
    {
        return new self(
            'AUTH_005',
            'auth.user_not_found',
            ['%identifier%' => $identifier]
        );
    }
}
```

## translations/auth.fr.yaml

```yaml
auth.invalid_credentials: "Identifiants incorrects pour %username%."
auth.account_locked: "Votre compte (%user_id%) est verrouillé jusqu'au %until%."
auth.token_expired: "Votre session a expiré, veuillez vous reconnecter."
auth.insufficient_permissions: "Vous n'avez pas les permissions pour accéder à %resource%."
auth.user_not_found: "Aucun utilisateur trouvé avec l'identifiant %identifier%."
```

## Exemple dans un contrôleur

```php
<?php

declare(strict_types=1);

namespace Acme\Infrastructure\Web\Controller;

use Acme\Application\Auth\Command\LoginUserCommand;
use Acme\Application\Auth\Command\LoginUserHandler;
use Acme\Domain\Auth\Exception\AuthExceptions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LoginController extends AbstractController
{
    public function __construct(
        private readonly LoginUserHandler $handler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            try {
                $this->handler->__invoke(
                    new LoginUserCommand(
                        email: $request->request->get('email', ''),
                        password: $request->request->get('password', '')
                    )
                );

                $this->addFlash('success', 'Connexion réussie !');
                return $this->redirectToRoute('app_dashboard');

            } catch (AuthExceptions $e) {
                // Traduction du message d'erreur
                $translatedMessage = $this->translator->trans(
                    $e->messageKey(),
                    $e->errorContext(),
                    $e->translationDomain()
                );

                // Ajout du flash message
                $this->addFlash('error', $translatedMessage);

                // Log optionnel avec le code d'erreur
                $this->container->get('logger')->error(
                    'Authentication error',
                    [
                        'error_code' => $e->errorCode(),
                        'context' => $e->errorContext(),
                    ]
                );
            }
        }

        return $this->render('auth/login.html.twig');
    }
}
```

## Utilisation dans un use case

```php
<?php

declare(strict_types=1);

namespace Acme\Application\Auth\Command;

use Acme\Domain\Auth\Exception\AuthExceptions;
use Acme\Domain\Auth\UserRepositoryInterface;

final readonly class LoginUserHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    public function __invoke(LoginUserCommand $command): void
    {
        $user = $this->userRepository->findByEmail($command->email);

        if (!$user) {
            throw AuthExceptions::userNotFound($command->email);
        }

        if (!$user->checkPassword($command->password)) {
            throw AuthExceptions::invalidCredentials($command->email);
        }

        if ($user->isLocked()) {
            throw AuthExceptions::accountLocked(
                $user->id(),
                $user->lockedUntil()
            );
        }

        // Logique de connexion...
    }
}
```

## Template Twig (templates/auth/login.html.twig)

```twig
{% extends 'base.html.twig' %}

{% block body %}
    <div class="container">
        <h1>Connexion</h1>

        {% for type, messages in app.flashes %}
            {% for message in messages %}
                <div class="alert alert-{{ type }}">
                    {{ message }}
                </div>
            {% endfor %}
        {% endfor %}

        <form method="post">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-primary">Se connecter</button>
        </form>
    </div>
{% endblock %}
```

Cette structure te permet de garder tes exceptions pures dans le domaine tout en gérant proprement la traduction et l'affichage au niveau infrastructure.[2][3][1]

[1](https://symfony.com/doc/current/translation.html)
[2](https://dev.to/eduarguz/exception-factories-for-better-code-4h3h)
[3](https://symfony.com/doc/5.4/the-fast-track/en/14-form.html)
