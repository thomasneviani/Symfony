
## Template général d'un Value Object

```php
<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final readonly class YourValueObject
{
    // ==========================================
    // 1. CONSTRUCTEUR PRIVÉ
    // ==========================================
    private function __construct(
        public string $property1,
        public int $property2
    ) {}
    
    // ==========================================
    // 2. NAMED CONSTRUCTORS (obligatoires)
    // ==========================================
    
    /**
     * Constructeur principal avec validation
     */
    public static function create(
        string $property1,
        int $property2
    ): self {
        self::validate($property1, $property2);
        
        return new self($property1, $property2);
    }
    
    /**
     * Alternative depuis une chaîne
     */
    public static function fromString(string $value): self
    {
        // Parse et crée l'objet
        // Exemple: "value1:123" → ['value1', 123]
        $parts = explode(':', $value);
        
        return self::create($parts[0], (int) $parts[1]);
    }
    
    /**
     * Alternative depuis un tableau (API, DB)
     */
    public static function fromArray(array $data): self
    {
        return self::create(
            $data['property1'] ?? throw new \DomainException('Missing property1'),
            $data['property2'] ?? throw new \DomainException('Missing property2')
        );
    }
    
    // ==========================================
    // 3. VALIDATION MÉTIER (privée)
    // ==========================================
    
    private static function validate(string $property1, int $property2): void
    {
        if (empty($property1)) {
            throw new \DomainException('Property1 cannot be empty');
        }
        
        if ($property2 <= 0) {
            throw new \DomainException('Property2 must be positive');
        }
        
        // Autres règles métier...
    }
    
    // ==========================================
    // 4. EQUALS (comparaison)
    // ==========================================
    
    public function equals(self $other): bool
    {
        return $this->property1 === $other->property1
            && $this->property2 === $other->property2;
    }
    
    // ==========================================
    // 5. STRING REPRESENTATION
    // ==========================================
    
    public function toString(): string
    {
        return sprintf(
            '%s:%d',
            $this->property1,
            $this->property2
        );
    }
    
    public function __toString(): string
    {
        return $this->toString();
    }
    
    // ==========================================
    // 6. COMPORTEMENTS MÉTIER (optionnels)
    // ==========================================
    
    // Queries (retournent une valeur)
    public function isValid(): bool
    {
        return $this->property2 > 0;
    }
    
    // Operations (retournent un nouveau VO)
    public function withProperty1(string $newValue): self
    {
        return self::create($newValue, $this->property2);
    }
}
```

## Les 6 fonctions essentielles

### 1️⃣ Constructeur privé

```php
// ✅ TOUJOURS privé
private function __construct(
    public string $value,
    public int $count
) {}
```

**Pourquoi** : Force l'utilisation des named constructors avec validation.[3]

### 2️⃣ Named constructor `create()`

```php
// ✅ Constructeur principal
public static function create(string $value, int $count): self
{
    self::validate($value, $count);
    return new self($value, $count);
}
```

**Pourquoi** : Point d'entrée principal avec validation métier.[2][3]

### 3️⃣ Méthode `validate()`

```php
// ✅ Validation métier privée
private static function validate(string $value, int $count): void
{
    if (empty($value)) {
        throw new \DomainException('Value cannot be empty');
    }
    
    if ($count < 0) {
        throw new \DomainException('Count must be positive');
    }
}
```

**Pourquoi** : Centralise toutes les règles métier.[4][3]

### 4️⃣ Méthode `equals()`

```php
// ✅ Comparaison entre VOs
public function equals(self $other): bool
{
    return $this->value === $other->value
        && $this->count === $other->count;
}
```

**Pourquoi** : Les VOs sont comparés par valeur, pas par référence.[1][2]

### 5️⃣ Méthodes `toString()` et `__toString()`

```php
// ✅ Représentation en string
public function toString(): string
{
    return sprintf('%s (%d)', $this->value, $this->count);
}

public function __toString(): string
{
    return $this->toString();
}
```

**Pourquoi** : Facilite le debug, les logs et l'affichage.[1]

### 6️⃣ Named constructors alternatifs

```php
// ✅ Depuis une string
public static function fromString(string $input): self
{
    // Parse et crée
}

// ✅ Depuis un tableau (API/DB)
public static function fromArray(array $data): self
{
    return self::create($data['value'], $data['count']);
}

// ✅ Cas par défaut
public static function empty(): self
{
    return self::create('', 0);
}
```

**Pourquoi** : Facilite la création depuis différentes sources.[2]

## Checklist complète pour un VO

```php
<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

// ✅ 1. Final + readonly
final readonly class MyValueObject
{
    // ✅ 2. Constructeur privé
    private function __construct(
        // ✅ 3. Propriétés publiques readonly
        public string $value
    ) {}
    
    // ✅ 4. Named constructor principal
    public static function create(string $value): self
    {
        self::validate($value);
        return new self($value);
    }
    
    // ✅ 5. Validation privée
    private static function validate(string $value): void
    {
        if (empty($value)) {
            throw new \DomainException('Value cannot be empty');
        }
    }
    
    // ✅ 6. Equals
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
    
    // ✅ 7. toString
    public function toString(): string
    {
        return $this->value;
    }
    
    public function __toString(): string
    {
        return $this->toString();
    }
    
    // ✅ 8. Named constructors alternatifs (selon besoin)
    public static function fromString(string $input): self
    {
        return self::create($input);
    }
    
    // ✅ 9. Comportements métier (selon besoin)
    public function isEmpty(): bool
    {
        return empty($this->value);
    }
    
    public function length(): int
    {
        return strlen($this->value);
    }
}
```

## Fonctions optionnelles selon le contexte

### Pour des VOs numériques (Money, Quantity)

```php
// Opérations mathématiques
public function add(self $other): self
public function subtract(self $other): self
public function multiply(float $multiplier): self
public function divide(float $divisor): self

// Comparaisons
public function isGreaterThan(self $other): bool
public function isLessThan(self $other): bool
public function isZero(): bool
public function isPositive(): bool
public function isNegative(): bool
```

### Pour des VOs temporels (Date, DateRange)

```php
// Queries
public function isInPast(): bool
public function isInFuture(): bool
public function isBetween(self $start, self $end): bool

// Operations
public function addDays(int $days): self
public function addMonths(int $months): self
```

### Pour des VOs textuels (Email, Name, Description)

```php
// Queries
public function isEmpty(): bool
public function length(): int
public function contains(string $needle): bool

// Operations
public function toUpperCase(): self
public function toLowerCase(): self
public function truncate(int $length): self
```

### Pour des VOs composites (Address, Coordinates)

```php
// Accesseurs métier
public function getCountry(): string
public function getCity(): string

// Validations contextuelles
public function isInEurope(): bool
public function isComplete(): bool
```

## Exemple minimal (le plus simple possible)

```php
<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final readonly class UserId
{
    private function __construct(public int $value) {}
    
    public static function create(int $value): self
    {
        if ($value <= 0) {
            throw new \DomainException('User ID must be positive');
        }
        return new self($value);
    }
    
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
    
    public function toString(): string
    {
        return (string) $this->value;
    }
    
    public function __toString(): string
    {
        return $this->toString();
    }
}
```

## Résumé : Les 6 fonctions à avoir TOUJOURS

| # | Fonction | Obligatoire | Objectif |
|---|----------|-------------|----------|
| 1 | `private __construct()` | ✅ Oui | Forcer les named constructors |
| 2 | `public static create()` | ✅ Oui | Constructeur principal avec validation |
| 3 | `private static validate()` | ✅ Oui | Validation métier centralisée |
| 4 | `public equals()` | ✅ Oui | Comparaison par valeur |
| 5 | `public toString()` | ✅ Oui | Représentation string |
| 6 | `public __toString()` | ✅ Oui | Support PHP natif |
