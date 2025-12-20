
## Les deux types de validation

### Validation DTO : Technique/Syntaxique[2][3][4]

**Objectif** : Vérifier que les données sont **techniquement correctes et bien formées**

**Type de règles** :
- Format des données (date valide, email valide, JSON valide)
- Types corrects (string, int, boolean)
- Longueurs min/max
- Présence de champs obligatoires
- Patterns/regex simples

**Exemple concret** :

```php
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AuthenticationResponseDto
{
    public function __construct(
        #[Assert\NotBlank]              // Champ obligatoire
        #[Assert\Type('integer')]       // Type technique
        #[Assert\Positive]              // Valeur positive
        public int $userId,
        
        #[Assert\NotBlank]
        #[Assert\Length(min: 10, max: 500)]  // Contrainte technique
        public string $accessToken,
        
        #[Assert\NotBlank]
        #[Assert\DateTime]              // Format datetime valide
        public string $expiresAt
    ) {}
}
```

**Questions auxquelles elle répond**  :[3][2]
- Les données sont-elles présentes ?
- Sont-elles du bon type ?
- Respectent-elles le format attendu ?
- Sont-elles dans les limites techniques acceptables ?

### Validation VO : Métier/Sémantique[5][4][1][3]

**Objectif** : Garantir que les données respectent les **règles métier du domaine**

**Type de règles** :
- Invariants métier
- Contraintes du domaine
- Règles de cohérence métier
- Logique contextuelle

**Exemple concret** :

```php
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
        // ✅ Règle métier : le token ne peut pas être expiré
        if ($expiresAt <= new \DateTimeImmutable()) {
            throw new \DomainException('Cannot create session with expired token');
        }
        
        // ✅ Règle métier : durée maximale de session = 24h
        $maxExpiration = (new \DateTimeImmutable())->modify('+24 hours');
        if ($expiresAt > $maxExpiration) {
            throw new \DomainException('Session cannot last more than 24 hours');
        }
        
        // ✅ Règle métier : token doit respecter notre politique
        if (strlen($token) < 32) {
            throw new \DomainException('Token must be at least 32 characters for security');
        }
        
        return new self($token, $userId, $expiresAt);
    }
}
```

**Questions auxquelles elle répond**  :[5][3]
- Les données ont-elles un sens métier ?
- Respectent-elles les règles du domaine ?
- Sont-elles cohérentes dans le contexte métier ?
- Maintiennent-elles les invariants du système ?

## Comparaison concrète avec exemples

| Aspect | Validation DTO | Validation VO |
|--------|---------------|---------------|
| **Couche** | Infrastructure/Application [3][4] | Domain [1][5] |
| **Type** | Technique/Syntaxique [2][3] | Métier/Sémantique [5][4] |
| **Outil** | Symfony Validator, annotations [2] | Code métier, if/throw [1][3] |
| **Responsabilité** | Données bien formées [3] | Règles métier respectées [1][5] |

### Exemple 1 : Date d'expiration

```php
// DTO : Validation technique
#[Assert\DateTime]  // ✅ "Est-ce une date valide ?"
#[Assert\NotBlank]  // ✅ "La date est-elle présente ?"
public string $expiresAt;

// VO : Validation métier
if ($expiresAt <= new \DateTimeImmutable()) {
    // ✅ "La date a-t-elle du sens métier ? (pas dans le passé)"
    throw new \DomainException('Token is already expired');
}

if ($expiresAt > (new \DateTimeImmutable())->modify('+24 hours')) {
    // ✅ "Respecte-t-elle la politique métier ? (max 24h)"
    throw new \DomainException('Session exceeds maximum duration');
}
```

### Exemple 2 : Email

```php
// DTO : Validation technique
#[Assert\NotBlank]
#[Assert\Email]     // ✅ Format email valide
#[Assert\Length(max: 255)]  // ✅ Longueur technique
public string $email;

// VO : Validation métier
final readonly class UserEmail
{
    private function __construct(public string $value) {}
    
    public static function fromString(string $email): self
    {
        // ✅ Règle métier : emails d'entreprise uniquement
        if (!str_ends_with($email, '@company.com')) {
            throw new \DomainException('Only company emails are allowed');
        }
        
        // ✅ Règle métier : pas d'alias
        if (str_contains($email, '+')) {
            throw new \DomainException('Email aliases are not permitted');
        }
        
        return new self($email);
    }
}
```

### Exemple 3 : Montant

```php
// DTO : Validation technique
#[Assert\NotBlank]
#[Assert\Type('numeric')]
#[Assert\PositiveOrZero]
public float $amount;

// VO : Validation métier
final readonly class OrderAmount
{
    private function __construct(
        public float $value,
        public string $currency
    ) {}
    
    public static function create(float $value, string $currency): self
    {
        // ✅ Règle métier : montant minimum de commande
        if ($value < 10.00) {
            throw new \DomainException('Minimum order amount is €10.00');
        }
        
        // ✅ Règle métier : montant maximum
        if ($value > 50000.00) {
            throw new \DomainException('Order exceeds maximum allowed amount');
        }
        
        // ✅ Règle métier : devises acceptées
        if (!in_array($currency, ['EUR', 'USD'])) {
            throw new \DomainException('Currency not supported');
        }
        
        return new self($value, $currency);
    }
}
```

## Pourquoi la double validation ?[4][2]

### Protection en couches[1][5]

```
API Externe (CRM)
    ↓
    └─ Données brutes ['user_id' => -5, 'token' => '', 'expires_at' => 'invalid']
    ↓
[1] Validation DTO (Barrière technique)
    ↓
    └─ Rejette : type incorrect, format invalide, champ manquant
    ↓
[2] Mapping DTO validé → VO
    ↓
[3] Validation VO (Barrière métier)
    ↓
    └─ Rejette : règle métier violée, invariant non respecté
    ↓
Domain (État toujours valide)
```

### Avantages[2][1][5]

**Sécurité** : Double protection contre données invalides[2]
- DTO bloque les données malformées
- VO bloque les violations métier

**Séparation des responsabilités**  :[3][4]
- Infrastructure gère les contraintes techniques
- Domain gère la logique métier
- Chaque couche a son rôle distinct

**Maintenabilité**  :[1][3]
- Changement de format API → modifier DTO uniquement
- Changement de règle métier → modifier VO uniquement
- Pas de couplage entre les deux validations

## Erreurs courantes à éviter

### ❌ Validation métier dans le DTO

```php
// ❌ Mauvais : règle métier dans l'infrastructure
final readonly class AuthenticationResponseDto
{
    public function __construct(
        #[Assert\Range(min: 1, max: 999999)]  // ❌ Limite métier dans DTO
        public int $userId,
        
        #[Assert\Callback]  // ❌ Validation métier complexe
        public string $expiresAt
    ) {}
    
    public function validateExpiration(): bool
    {
        // ❌ Logique métier dans DTO
        return strtotime($this->expiresAt) <= strtotime('+24 hours');
    }
}
```

### ❌ Validation technique dans le VO

```php
// ❌ Mauvais : validation technique dans le domaine
final readonly class SessionKey
{
    public static function create(string $token, int $userId, string $expiresAt): self
    {
        // ❌ Vérification de format (technique) dans le domaine
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $expiresAt)) {
            throw new \DomainException('Invalid date format');
        }
        
        // ❌ Vérification de type (technique) dans le domaine
        if (!is_int($userId)) {
            throw new \DomainException('User ID must be integer');
        }
    }
}
```

## Résumé : Qui valide quoi ?

**DTO (Infrastructure)**  :[4][3][2]
- ✅ Format valide (email, datetime, JSON)
- ✅ Type correct (int, string, boolean)
- ✅ Longueur min/max
- ✅ Champs obligatoires présents
- ❌ **Pas** de règles métier

**VO (Domain)**  :[5][3][4][1]
- ✅ Règles métier (durée max, montant min)
- ✅ Invariants du domaine
- ✅ Cohérence contextuelle
- ✅ Logique métier
- ❌ **Pas** de validation de format/type

