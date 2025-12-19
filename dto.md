
## Pourquoi cette approche est correcte

Tu appliques une **double validation en couches**  :[2][3][4]

1. **Validation à la frontière** (DTO + Symfony Validator) : validation technique/structurelle[5][1]
2. **Validation métier** (Value Object) : règles du domaine[3][2]

Cette séparation respecte le principe de Clean Architecture où chaque couche a ses propres validations.[4]

## Architecture recommandée

### 1. DTO avec Symfony Validator (couche Infrastructure/API)

```php
// src/Erp/PublishedLanguage/DTO/AuthenticationResponseDto.php
namespace App\Erp\PublishedLanguage\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AuthenticationResponseDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Positive]
        public int $userId,
        
        #[Assert\NotBlank]
        #[Assert\Length(min: 10)]
        public string $accessToken,
        
        #[Assert\NotBlank]
        #[Assert\DateTime]
        public string $expiresAt
    ) {}
}
```

**Rôle** : validation **technique et structurelle** des données de l'API  :[1][5]
- Types corrects
- Formats valides
- Champs obligatoires présents
- Contraintes de longueur/format basiques

### 2. Validation du DTO après réception API

```php
// src/Erp/Domain/Service/ErpAuthenticationService.php
namespace App\Erp\Domain\Service;

use App\Erp\PublishedLanguage\DTO\AuthenticationResponseDto;
use App\Erp\Infrastructure\Client\CrmApiClient;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

class ErpAuthenticationService implements AuthenticationApiInterface
{
    public function __construct(
        private readonly CrmApiClient $crmClient,
        private readonly ValidatorInterface $validator
    ) {}
    
    public function login(string $username, string $password): AuthenticationResponseDto
    {
        $crmResponse = $this->crmClient->call('POST', '/auth/login', [
            'username' => $username,
            'password' => $password
        ]);
        
        // Créer le DTO depuis les données brutes
        $dto = new AuthenticationResponseDto(
            userId: $crmResponse['id'],
            accessToken: $crmResponse['access_token'],
            expiresAt: $crmResponse['token_expiration']
        );
        
        // Valider le DTO avec Symfony Validator
        $violations = $this->validator->validate($dto);
        
        if (count($violations) > 0) {
            throw new ValidationFailedException($dto, $violations);
        }
        
        return $dto;
    }
}
```

### 3. Value Object avec validation métier (couche Domain)

```php
// src/Authentication/Domain/Model/SessionKey.php
namespace App\Authentication\Domain\Model;

final readonly class SessionKey
{
    private function __construct(
        public string $token,
        public int $userId,
        public \DateTimeImmutable $expiresAt
    ) {}
    
    public static function create(
        string $token,
        int $userId,
        \DateTimeImmutable $expiresAt
    ): self {
        // Validation MÉTIER (règles du domaine Authentication)
        if (empty($token)) {
            throw new \DomainException('Session token cannot be empty');
        }
        
        if ($userId <= 0) {
            throw new \DomainException('User ID must be a positive integer');
        }
        
        // Règle métier : un token expiré n'est pas une session valide
        if ($expiresAt <= new \DateTimeImmutable()) {
            throw new \DomainException('Cannot create session with expired token');
        }
        
        // Règle métier : token ne peut pas expirer dans plus de 24h
        $maxExpiration = (new \DateTimeImmutable())->modify('+24 hours');
        if ($expiresAt > $maxExpiration) {
            throw new \DomainException('Token expiration cannot exceed 24 hours');
        }
        
        return new self($token, $userId, $expiresAt);
    }
    
    // Logique métier
    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }
    
    public function isAboutToExpire(int $minutesThreshold = 5): bool
    {
        $threshold = (new \DateTimeImmutable())->modify("+{$minutesThreshold} minutes");
        return $this->expiresAt <= $threshold;
    }
}
```

### 4. Mapping DTO → VO dans l'ACL

```php
// src/Authentication/Infrastructure/Adapter/ErpAuthenticationAdapter.php
namespace App\Authentication\Infrastructure\Adapter;

use App\Authentication\Domain\Port\AuthenticationProviderInterface;
use App\Authentication\Domain\Model\SessionKey;
use App\Erp\PublishedLanguage\AuthenticationApiInterface;

class ErpAuthenticationAdapter implements AuthenticationProviderInterface
{
    public function __construct(
        private readonly AuthenticationApiInterface $erpAuthApi
    ) {}
    
    public function login(string $username, string $password): SessionKey
    {
        // 1. Récupère le DTO validé depuis ERP
        $dto = $this->erpAuthApi->login($username, $password);
        
        // 2. Mappe DTO → Value Object avec validation métier
        return SessionKey::create(
            token: $dto->accessToken,
            userId: $dto->userId,
            expiresAt: new \DateTimeImmutable($dto->expiresAt)
        );
    }
}
```

## Séparation des responsabilités

| Couche | Type | Validation | Objectif |
|--------|------|------------|----------|
| **Infrastructure/API** | DTO + Symfony Validator | Technique/Structurelle [1][5] | Données bien formées |
| **Domain** | Value Object | Métier/Invariants [2][3] | Cohérence du domaine |

### Validation DTO (frontière)[4][5][1]

- Format des données (email, date, UUID)
- Types corrects (string, int, array)
- Longueurs min/max
- Champs requis/optionnels
- **Protection contre données malformées de l'API externe**

### Validation VO (domaine)[2][3]

- Règles métier (ex: token max 24h)
- Invariants du domaine
- Contraintes contextuelles
- **Garantit la cohérence du modèle métier**

## Exemple avec validation conditionnelle

```php
// DTO avec validation Symfony avancée
final readonly class AuthenticationResponseDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Positive]
        public int $userId,
        
        #[Assert\NotBlank]
        #[Assert\Length(min: 10, max: 500)]
        #[Assert\Regex(pattern: '/^[A-Za-z0-9\-._~+\/]+=*$/', message: 'Invalid token format')]
        public string $accessToken,
        
        #[Assert\NotBlank]
        #[Assert\DateTime(format: \DateTimeInterface::ATOM)]
        public string $expiresAt,
        
        #[Assert\Choice(choices: ['FR', 'BE', 'UK', 'DE'])]
        public ?string $countryCode = null
    ) {}
}
```

## Gestion des erreurs

```php
try {
    // Validation DTO échoue → problème avec l'API externe
    $dto = $this->erpAuthApi->login($username, $password);
} catch (ValidationFailedException $e) {
    // Log et remonte une erreur infrastructure
    $this->logger->error('Invalid data from CRM API', [
        'violations' => (string) $e->getViolations()
    ]);
    throw new ExternalApiException('CRM API returned invalid data');
}

try {
    // Validation VO échoue → violation de règle métier
    $sessionKey = SessionKey::create($dto->accessToken, $dto->userId, $expiresAt);
} catch (\DomainException $e) {
    // Erreur métier, peut être exposée à l'utilisateur
    throw new AuthenticationException($e->getMessage());
}
```
**Non, tu ne dois PAS coupler ton Value Object à Symfony Validator**. Voici pourquoi et comment gérer la validation.[1][2]

## Séparation des validations

### DTO : Symfony Validator (Infrastructure)

```php
// src/Erp/PublishedLanguage/DTO/AuthenticationResponseDto.php
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AuthenticationResponseDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Positive]
        public int $userId,
        
        #[Assert\NotBlank]
        #[Assert\Length(min: 10)]
        public string $accessToken,
        
        #[Assert\NotBlank]
        #[Assert\DateTime]
        public string $expiresAt
    ) {}
}
```

**Utilise Symfony Validator** car le DTO est dans la couche Infrastructure.[3][4]

### Value Object : Validation manuelle (Domain)

```php
// src/Authentication/Domain/Model/SessionKey.php
namespace App\Authentication\Domain\Model;

final readonly class SessionKey
{
    private function __construct(
        public string $token,
        public int $userId,
        public \DateTimeImmutable $expiresAt
    ) {}
    
    public static function create(
        string $token,
        int $userId,
        \DateTimeImmutable $expiresAt
    ): self {
        // ✅ Validation MANUELLE dans le domaine
        if (empty($token)) {
            throw new \DomainException('Session token cannot be empty');
        }
        
        if ($userId <= 0) {
            throw new \DomainException('User ID must be positive');
        }
        
        if ($expiresAt <= new \DateTimeImmutable()) {
            throw new \DomainException('Token is already expired');
        }
        
        $maxExpiration = (new \DateTimeImmutable())->modify('+24 hours');
        if ($expiresAt > $maxExpiration) {
            throw new \DomainException('Token expiration exceeds 24 hours limit');
        }
        
        return new self($token, $userId, $expiresAt);
    }
}
```

**N'utilise PAS Symfony Validator** car le VO est dans le domaine.[2][1]

## Pourquoi ne pas utiliser Symfony Validator sur les VO

### 1. Violation de Clean Architecture[5][2]

Utiliser Symfony Validator dans ton domaine crée une **dépendance vers le framework** :

```php
// ❌ Mauvais : couplage du Domain à Symfony
namespace App\Authentication\Domain\Model;

use Symfony\Component\Validator\Constraints as Assert; // ❌ Dépendance framework

final readonly class SessionKey
{
    public function __construct(
        #[Assert\NotBlank]  // ❌ Domain couplé à Infrastructure
        public string $token,
    ) {}
}
```

Ton **domaine doit être framework-agnostic**.[1][2]

### 2. Perte de contrôle sur la logique métier

Les annotations Symfony Validator sont **déclaratives et limitées**  :[1]

```php
// ❌ Impossible d'exprimer cette règle métier avec des annotations
#[Assert\???] // Comment valider "max 24h dans le futur" ?
public \DateTimeImmutable $expiresAt;
```

La validation manuelle est **plus expressive**  :[2][1]

```php
// ✅ Règle métier claire et testable
if ($expiresAt > (new \DateTimeImmutable())->modify('+24 hours')) {
    throw new \DomainException('Token expiration exceeds 24 hours limit');
}
```

### 3. Testabilité et portabilité

```php
// ✅ Tests unitaires purs, sans dépendances
public function testCannotCreateExpiredSession(): void
{
    $this->expectException(\DomainException::class);
    
    SessionKey::create(
        token: 'abc123',
        userId: 1,
        expiresAt: new \DateTimeImmutable('-1 hour') // Déjà expiré
    );
}
```

Pas besoin de charger Symfony ou le ValidatorInterface pour tester.[2][1]

## Architecture complète recommandée

### Flux de validation en deux étapes

```php
// 1. Infrastructure : Validation technique avec Symfony Validator
class ErpAuthenticationService
{
    public function login(string $username, string $password): AuthenticationResponseDto
    {
        $crmResponse = $this->crmClient->call('POST', '/auth/login', [...]);
        
        $dto = new AuthenticationResponseDto(
            userId: $crmResponse['id'],
            accessToken: $crmResponse['access_token'],
            expiresAt: $crmResponse['token_expiration']
        );
        
        // Validation Symfony
        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            throw new ValidationFailedException($dto, $violations);
        }
        
        return $dto;
    }
}

// 2. Domain : Validation métier manuelle dans le VO
class ErpAuthenticationAdapter
{
    public function login(string $username, string $password): SessionKey
    {
        $dto = $this->erpAuthApi->login($username, $password);
        
        // Validation métier automatique via factory method
        return SessionKey::create(
            token: $dto->accessToken,
            userId: $dto->userId,
            expiresAt: new \DateTimeImmutable($dto->expiresAt)
        );
    }
}
```

## Pattern recommandé pour les Value Objects

### Named constructor avec validation

```php
final readonly class SessionKey
{
    private function __construct(
        public string $token,
        public int $userId,
        public \DateTimeImmutable $expiresAt
    ) {}
    
    // Factory method avec validation
    public static function create(
        string $token,
        int $userId,
        \DateTimeImmutable $expiresAt
    ): self {
        self::validate($token, $userId, $expiresAt);
        return new self($token, $userId, $expiresAt);
    }
    
    private static function validate(
        string $token,
        int $userId,
        \DateTimeImmutable $expiresAt
    ): void {
        if (empty($token)) {
            throw new \DomainException('Session token cannot be empty');
        }
        
        if ($userId <= 0) {
            throw new \DomainException('User ID must be positive');
        }
        
        if ($expiresAt <= new \DateTimeImmutable()) {
            throw new \DomainException('Token is already expired');
        }
        
        $maxExpiration = (new \DateTimeImmutable())->modify('+24 hours');
        if ($expiresAt > $maxExpiration) {
            throw new \DomainException('Token expiration exceeds 24 hours limit');
        }
    }
}
```

### Alternative : méthode de validation dédiée

```php
final readonly class Email
{
    private function __construct(public string $value) {}
    
    public static function fromString(string $email): self
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException("Invalid email format: {$email}");
        }
        
        if (strlen($email) > 255) {
            throw new \DomainException('Email exceeds maximum length of 255 characters');
        }
        
        return new self($email);
    }
}
```
