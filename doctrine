## Principe fondamental : Séparation Domain / Persistence

En Clean Architecture, **l'infrastructure (Doctrine) ne doit pas polluer le domaine**. Vous avez donc :[3][4]

1. **Entités de Domaine** (Domain Layer) : Logique métier pure, sans annotations Doctrine
2. **Entités Doctrine** (Infrastructure Layer) : Mapping avec la base de données

## Architecture recommandée

### Structure des dossiers

```
src/
├── Domain/
│   └── User/
│       ├── Entity/
│       │   └── User.php              # Entité métier PURE
│       ├── ValueObject/
│       │   ├── UserId.php
│       │   └── Email.php
│       └── Repository/
│           └── UserRepositoryInterface.php  # Interface du domaine
│
└── Infrastructure/
    └── Persistence/
        ├── Doctrine/
        │   ├── Entity/
        │   │   └── UserEntity.php     # Entité Doctrine (DTO persistence)
        │   ├── Repository/
        │   │   └── DoctrineUserRepository.php
        │   └── Mapper/
        │       └── UserMapper.php     # Conversion Domain <-> Doctrine
        └── Mapping/
            └── UserEntity.orm.xml     # Mapping XML (pas d'annotations!)
```

### Entité de Domaine (sans Doctrine)

```php
<?php
// src/Domain/User/Entity/User.php
namespace App\Domain\User\Entity;

use App\Domain\User\ValueObject\UserId;
use App\Domain\User\ValueObject\Email;

class User
{
    private function __construct(
        private UserId $id,
        private Email $email,
        private string $username,
        private \DateTimeImmutable $createdAt
    ) {}
    
    public static function create(Email $email, string $username): self
    {
        return new self(
            id: UserId::generate(),
            email: $email,
            username: $username,
            createdAt: new \DateTimeImmutable()
        );
    }
    
    public function changeEmail(Email $newEmail): void
    {
        // Logique métier pure
        if ($this->email->equals($newEmail)) {
            throw new \DomainException('Same email');
        }
        
        $this->email = $newEmail;
    }
    
    // Getters
    public function getId(): UserId { return $this->id; }
    public function getEmail(): Email { return $this->email; }
    public function getUsername(): string { return $this->username; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
```

### Entité Doctrine (Infrastructure)

```php
<?php
// src/Infrastructure/Persistence/Doctrine/Entity/UserEntity.php
namespace App\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users')] // Garde le nom de table legacy
class UserEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;
    
    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $email;
    
    #[ORM\Column(type: 'string', length: 100)]
    private string $username;
    
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    
    // Getters/Setters simples (pas de logique métier!)
    public function getId(): string { return $this->id; }
    public function setId(string $id): void { $this->id = $id; }
    
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    
    public function getUsername(): string { return $this->username; }
    public function setUsername(string $username): void { $this->username = $username; }
    
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): void { $this->createdAt = $createdAt; }
}
```

### Mapper (Conversion Domain ↔ Doctrine)

```php
<?php
// src/Infrastructure/Persistence/Doctrine/Mapper/UserMapper.php
namespace App\Infrastructure\Persistence\Doctrine\Mapper;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\UserId;
use App\Domain\User\ValueObject\Email;
use App\Infrastructure\Persistence\Doctrine\Entity\UserEntity;

class UserMapper
{
    public function toDomain(UserEntity $entity): User
    {
        // Utilise la réflexion pour construire l'entité de domaine
        $reflection = new \ReflectionClass(User::class);
        $user = $reflection->newInstanceWithoutConstructor();
        
        $this->setPrivateProperty($user, 'id', new UserId($entity->getId()));
        $this->setPrivateProperty($user, 'email', new Email($entity->getEmail()));
        $this->setPrivateProperty($user, 'username', $entity->getUsername());
        $this->setPrivateProperty($user, 'createdAt', $entity->getCreatedAt());
        
        return $user;
    }
    
    public function toEntity(User $domain): UserEntity
    {
        $entity = new UserEntity();
        $entity->setId($domain->getId()->value());
        $entity->setEmail($domain->getEmail()->value());
        $entity->setUsername($domain->getUsername());
        $entity->setCreatedAt($domain->getCreatedAt());
        
        return $entity;
    }
    
    public function updateEntity(User $domain, UserEntity $entity): void
    {
        $entity->setEmail($domain->getEmail()->value());
        $entity->setUsername($domain->getUsername());
    }
    
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
```

### Repository Infrastructure

```php
<?php
// src/Infrastructure/Persistence/Doctrine/Repository/DoctrineUserRepository.php
namespace App\Infrastructure\Persistence\Doctrine\Repository;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\UserId;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Infrastructure\Persistence\Doctrine\Entity\UserEntity;
use App\Infrastructure\Persistence\Doctrine\Mapper\UserMapper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DoctrineUserRepository extends ServiceEntityRepository implements UserRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly UserMapper $mapper
    ) {
        parent::__construct($registry, UserEntity::class);
    }
    
    public function save(User $user): void
    {
        $entity = $this->find($user->getId()->value());
        
        if ($entity === null) {
            $entity = $this->mapper->toEntity($user);
            $this->getEntityManager()->persist($entity);
        } else {
            $this->mapper->updateEntity($user, $entity);
        }
        
        $this->getEntityManager()->flush();
    }
    
    public function findById(UserId $id): ?User
    {
        $entity = $this->find($id->value());
        
        return $entity ? $this->mapper->toDomain($entity) : null;
    }
    
    public function findByEmail(string $email): ?User
    {
        $entity = $this->findOneBy(['email' => $email]);
        
        return $entity ? $this->mapper->toDomain($entity) : null;
    }
}
```

## Gestion de la base legacy : Migration progressive

### Ne pas toucher la structure existante immédiatement

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        schema_filter: '~^(?!legacy_)~'  # Ignore les tables non migrées
    
    orm:
        auto_generate_proxy_classes: true
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
        mappings:
            App:
                is_bundle: false
                dir: '%kernel.project_dir%/src/Infrastructure/Persistence/Doctrine/Entity'
                prefix: 'App\Infrastructure\Persistence\Doctrine\Entity'
                alias: App
```

### Approche par étapes

**Phase 1 : Mapping de la table existante (sans modification)**

```php
#[ORM\Entity]
#[ORM\Table(name: 'users')] // Table legacy existante
class UserEntity
{
    // Mapper EXACTEMENT les colonnes existantes
    #[ORM\Column(name: 'id', type: 'integer')] // Même si c'est un mauvais type
    private int $id;
    
    // Pas d'index, pas de FK pour le moment
}
```

**Phase 2 : Ajouter progressivement les contraintes avec migrations**[2]

```php
<?php
// migrations/Version20251206120000.php
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251206120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes and foreign keys to users table';
    }

    public function up(Schema $schema): void
    {
        // Ajouter les index manquants progressivement
        $this->addSql('CREATE UNIQUE INDEX UNIQ_users_email ON users (email)');
        $this->addSql('CREATE INDEX IDX_users_created_at ON users (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_users_email');
        $this->addSql('DROP INDEX IDX_users_created_at');
    }
}
```

**Phase 3 : Ajouter les FK une fois les données nettoyées**

```php
// Uniquement quand les données respectent l'intégrité référentielle
$this->addSql('
    ALTER TABLE orders 
    ADD CONSTRAINT FK_orders_user_id 
    FOREIGN KEY (user_id) REFERENCES users(id) 
    ON DELETE CASCADE
');
```

## Alternative : Mapping XML (recommandé pour DDD pur)[2]

```xml
<!-- src/Infrastructure/Persistence/Mapping/UserEntity.orm.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                  https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
    
    <entity name="App\Infrastructure\Persistence\Doctrine\Entity\UserEntity" table="users">
        <id name="id" type="string" length="36">
            <generator strategy="NONE"/>
        </id>
        
        <field name="email" type="string" length="255" unique="true"/>
        <field name="username" type="string" length="100"/>
        <field name="createdAt" type="datetime_immutable" column="created_at"/>
        
        <indexes>
            <index columns="email" name="idx_users_email"/>
        </indexes>
    </entity>
</doctrine-mapping>
```

## Réponse directe à vos questions

1. **Migrer avec Doctrine ?** Oui, mais seulement dans l'Infrastructure Layer[4][1]
2. **Créer des repos ?** Oui, deux types : interface dans Domain, implémentation Doctrine dans Infrastructure[4][2]
3. **Annotations sur entités ?** Non sur les entités de domaine ! Uniquement sur les entités Doctrine (ou mieux : XML mapping)[2]
4. **Index/FK manquants ?** Ajoutez-les progressivement via migrations, après avoir nettoyé les données legacy[5][6]
