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
