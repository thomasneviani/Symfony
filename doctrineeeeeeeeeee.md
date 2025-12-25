# Option 1 : Exemple minimal (mapping XML)

## Structure

```
src/YourBC/
├── Domain/
│   ├── Model/
│   │   └── User.php
│   └── Repository/
│       └── UserRepositoryInterface.php
└── Infrastructure/
    └── Persistence/
        └── Doctrine/
            ├── Mapping/
            │   └── User.orm.xml
            └── UserRepository.php
```

***

## 1. Entité pure du domaine

```php
// src/YourBC/Domain/Model/User.php
<?php

namespace App\YourBC\Domain\Model;

/**
 * Entité PURE - aucune dépendance Doctrine
 */
final class User
{
    private string $id;
    private string $email;
    private string $name;

    public function __construct(string $id, string $email, string $name)
    {
        $this->id = $id;
        $this->email = $email;
        $this->name = $name;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function changeEmail(string $newEmail): void
    {
        if (empty($newEmail)) {
            throw new \DomainException('Email cannot be empty');
        }
        $this->email = $newEmail;
    }
}
```

***

## 2. Interface repository (domaine)

```php
// src/YourBC/Domain/Repository/UserRepositoryInterface.php
<?php

namespace App\YourBC\Domain\Repository;

use App\YourBC\Domain\Model\User;

interface UserRepositoryInterface
{
    public function save(User $user): void;
    
    public function findById(string $id): ?User;
}
```

***

## 3. Mapping Doctrine (XML)

```xml
<!-- src/YourBC/Infrastructure/Persistence/Doctrine/Mapping/User.orm.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                  https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">

    <entity name="App\YourBC\Domain\Model\User" table="users">
        <id name="id" type="string" length="36">
            <generator strategy="NONE"/>
        </id>
        
        <field name="email" type="string" length="255" nullable="false"/>
        <field name="name" type="string" length="100" nullable="false"/>
    </entity>

</doctrine-mapping>
```

***

## 4. Repository Doctrine

```php
// src/YourBC/Infrastructure/Persistence/Doctrine/UserRepository.php
<?php

namespace App\YourBC\Infrastructure\Persistence\Doctrine;

use App\YourBC\Domain\Model\User;
use App\YourBC\Domain\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }
}
```

***

## 5. Configuration Doctrine

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'

    orm:
        auto_generate_proxy_classes: true
        enable_lazy_ghost_objects: true
        
        mappings:
            YourBC:
                type: xml
                dir: '%kernel.project_dir%/src/YourBC/Infrastructure/Persistence/Doctrine/Mapping'
                prefix: 'App\YourBC\Domain\Model'
                is_bundle: false
```

***

## 6. Service binding

```yaml
# config/services.yaml
services:
    # Binding du repository
    App\YourBC\Domain\Repository\UserRepositoryInterface:
        class: App\YourBC\Infrastructure\Persistence\Doctrine\UserRepository
```

***

## Utilisation

```php
// Dans un use case ou contrôleur
final readonly class CreateUserUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {}

    public function execute(string $email, string $name): void
    {
        $user = new User(
            id: uniqid(),
            email: $email,
            name: $name
        );

        $this->userRepository->save($user);
    }
}
```

***

## Avantages

✅ **Entité pure** : aucune annotation Doctrine[1]
✅ **Séparation** : mapping isolé dans l'infrastructure[1]
✅ **Simple** : un seul fichier à mapper (pas de duplication)  
✅ **Doctrine transparent** : hydrate directement ton entité[1]
✅ **Migration legacy** : mappe facilement vers tables existantes[2][3]

Cette approche est **le standard DDD recommandé** pour Symfony.[4][1]

[1](https://github.com/dotnet-architecture/eShopOnContainers/issues/923)
[2](https://sensiolabs.com/blog/2025/rewriting-vs-refactoring-legacy-php)
[3](https://www.scribd.com/document/807351109/PHP-Framework-migration-From-legacy-to-symfony-SMILE-1)
[4](https://www.linkedin.com/pulse/domain-driven-design-patterns-integratingmigrating-legacy-k-9wr2c)
