Parfait ! Voici les **fichiers essentiels minimum** pour démarrer.[1][2]

## 1. Value Objects

```php
// src/Domain/Booking/ValueObject/BookingId.php
<?php

declare(strict_types=1);

namespace App\Domain\Booking\ValueObject;

use Symfony\Component\Uid\Uuid;

final readonly class BookingId
{
    private function __construct(private string $value) {}
    
    public static function generate(): self
    {
        return new self(Uuid::v4()->toRfc4122());
    }
    
    public static function fromString(string $value): self
    {
        return new self($value);
    }
    
    public function value(): string
    {
        return $this->value;
    }
}
```

```php
// src/Domain/Booking/ValueObject/BookingStatus.php
<?php

declare(strict_types=1);

namespace App\Domain\Booking\ValueObject;

final readonly class BookingStatus
{
    private function __construct(private string $value) {}
    
    public static function pending(): self
    {
        return new self('pending');
    }
    
    public static function confirmed(): self
    {
        return new self('confirmed');
    }
    
    public static function fromString(string $value): self
    {
        return new self($value);
    }
    
    public function value(): string
    {
        return $this->value;
    }
    
    public function isPending(): bool
    {
        return $this->value === 'pending';
    }
}
```

```php
// src/Domain/Booking/ValueObject/Money.php
<?php

declare(strict_types=1);

namespace App\Domain\Booking\ValueObject;

final readonly class Money
{
    private function __construct(
        private float $amount,
        private string $currency = 'EUR'
    ) {}
    
    public static function fromAmount(float $amount, string $currency = 'EUR'): self
    {
        return new self($amount, $currency);
    }
    
    public function amount(): float
    {
        return $this->amount;
    }
    
    public function currency(): string
    {
        return $this->currency;
    }
}
```

## 2. Entité de Domaine

```php
// src/Domain/Booking/Entity/Booking.php
<?php

declare(strict_types=1);

namespace App\Domain\Booking\Entity;

use App\Domain\Booking\ValueObject\BookingId;
use App\Domain\Booking\ValueObject\BookingStatus;
use App\Domain\Booking\ValueObject\Money;

final class Booking
{
    private function __construct(
        private BookingId $id,
        private string $customerId,
        private \DateTimeImmutable $createdAt,
        private BookingStatus $status,
        private Money $totalAmount,
        private ?string $reference,
        private ?\DateTimeImmutable $confirmedAt
    ) {}
    
    public static function create(
        BookingId $id,
        string $customerId,
        Money $totalAmount,
        ?string $reference = null
    ): self {
        return new self(
            id: $id,
            customerId: $customerId,
            createdAt: new \DateTimeImmutable(),
            status: BookingStatus::pending(),
            totalAmount: $totalAmount,
            reference: $reference,
            confirmedAt: null
        );
    }
    
    public static function reconstitute(
        BookingId $id,
        string $customerId,
        \DateTimeImmutable $createdAt,
        BookingStatus $status,
        Money $totalAmount,
        ?string $reference,
        ?\DateTimeImmutable $confirmedAt
    ): self {
        return new self(
            id: $id,
            customerId: $customerId,
            createdAt: $createdAt,
            status: $status,
            totalAmount: $totalAmount,
            reference: $reference,
            confirmedAt: $confirmedAt
        );
    }
    
    public function confirm(): void
    {
        $this->status = BookingStatus::confirmed();
        $this->confirmedAt = new \DateTimeImmutable();
    }
    
    public function cancel(): void
    {
        $this->status = BookingStatus::cancelled();
    }
    
    public function getId(): BookingId { return $this->id; }
    public function getCustomerId(): string { return $this->customerId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStatus(): BookingStatus { return $this->status; }
    public function getTotalAmount(): Money { return $this->totalAmount; }
    public function getReference(): ?string { return $this->reference; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
}
```

## 3. Repository Interface

```php
// src/Domain/Booking/Repository/BookingRepositoryInterface.php
<?php

declare(strict_types=1);

namespace App\Domain\Booking\Repository;

use App\Domain\Booking\Entity\Booking;
use App\Domain\Booking\ValueObject\BookingId;

interface BookingRepositoryInterface
{
    public function save(Booking $booking): void;
    
    public function findById(BookingId $id): ?Booking;
    
    public function nextIdentity(): BookingId;
}
```

## 4. Entité Doctrine

```php
// src/Infrastructure/Persistence/Doctrine/Entity/BookingEntity.php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bookings')]
class BookingEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;
    
    #[ORM\Column(name: 'customer_id', type: 'string', length: 36)]
    private string $customerId;
    
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    
    #[ORM\Column(type: 'string', length: 20)]
    private string $status;
    
    #[ORM\Column(name: 'total_amount', type: 'float')]
    private float $totalAmount;
    
    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;
    
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $reference;
    
    #[ORM\Column(name: 'confirmed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt;
    
    public function getId(): string { return $this->id; }
    public function setId(string $id): void { $this->id = $id; }
    
    public function getCustomerId(): string { return $this->customerId; }
    public function setCustomerId(string $customerId): void { $this->customerId = $customerId; }
    
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): void { $this->createdAt = $createdAt; }
    
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
    
    public function getTotalAmount(): float { return $this->totalAmount; }
    public function setTotalAmount(float $totalAmount): void { $this->totalAmount = $totalAmount; }
    
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): void { $this->currency = $currency; }
    
    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): void { $this->reference = $reference; }
    
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): void { $this->confirmedAt = $confirmedAt; }
}
```

## 5. Mapper

```php
// src/Infrastructure/Persistence/Mapper/BookingMapper.php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mapper;

use App\Domain\Booking\Entity\Booking;
use App\Domain\Booking\ValueObject\BookingId;
use App\Domain\Booking\ValueObject\BookingStatus;
use App\Domain\Booking\ValueObject\Money;
use App\Infrastructure\Persistence\Doctrine\Entity\BookingEntity;

final readonly class BookingMapper
{
    public function toDomain(BookingEntity $entity): Booking
    {
        return Booking::reconstitute(
            id: BookingId::fromString($entity->getId()),
            customerId: $entity->getCustomerId(),
            createdAt: $entity->getCreatedAt(),
            status: BookingStatus::fromString($entity->getStatus()),
            totalAmount: Money::fromAmount($entity->getTotalAmount(), $entity->getCurrency()),
            reference: $entity->getReference(),
            confirmedAt: $entity->getConfirmedAt()
        );
    }
    
    public function toEntity(Booking $booking): BookingEntity
    {
        $entity = new BookingEntity();
        $entity->setId($booking->getId()->value());
        $entity->setCustomerId($booking->getCustomerId());
        $entity->setCreatedAt($booking->getCreatedAt());
        $entity->setStatus($booking->getStatus()->value());
        $entity->setTotalAmount($booking->getTotalAmount()->amount());
        $entity->setCurrency($booking->getTotalAmount()->currency());
        $entity->setReference($booking->getReference());
        $entity->setConfirmedAt($booking->getConfirmedAt());
        return $entity;
    }
    
    public function updateEntity(Booking $booking, BookingEntity $entity): void
    {
        $entity->setStatus($booking->getStatus()->value());
        $entity->setTotalAmount($booking->getTotalAmount()->amount());
        $entity->setCurrency($booking->getTotalAmount()->currency());
        $entity->setReference($booking->getReference());
        $entity->setConfirmedAt($booking->getConfirmedAt());
    }
}
```

## 6. Repository Doctrine

```php
// src/Infrastructure/Persistence/Doctrine/Repository/DoctrineBookingRepository.php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Repository;

use App\Domain\Booking\Entity\Booking;
use App\Domain\Booking\Repository\BookingRepositoryInterface;
use App\Domain\Booking\ValueObject\BookingId;
use App\Infrastructure\Persistence\Doctrine\Entity\BookingEntity;
use App\Infrastructure\Persistence\Mapper\BookingMapper;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineBookingRepository implements BookingRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingMapper $mapper
    ) {}
    
    public function save(Booking $booking): void
    {
        $entity = $this->entityManager
            ->getRepository(BookingEntity::class)
            ->find($booking->getId()->value());
        
        if ($entity) {
            $this->mapper->updateEntity($booking, $entity);
        } else {
            $entity = $this->mapper->toEntity($booking);
            $this->entityManager->persist($entity);
        }
        
        $this->entityManager->flush();
    }
    
    public function findById(BookingId $id): ?Booking
    {
        $entity = $this->entityManager
            ->getRepository(BookingEntity::class)
            ->find($id->value());
        
        return $entity ? $this->mapper->toDomain($entity) : null;
    }
    
    public function nextIdentity(): BookingId
    {
        return BookingId::generate();
    }
}
```

## 7. Configuration

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
    
    App\Domain\:
        resource: '../src/Domain/'
    
    App\Infrastructure\:
        resource: '../src/Infrastructure/'
    
    App\Domain\Booking\Repository\BookingRepositoryInterface:
        class: App\Infrastructure\Persistence\Doctrine\Repository\DoctrineBookingRepository
```

## 8. Utilisation dans un Controller

```php
// src/Controller/BookingController.php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Booking\Entity\Booking;
use App\Domain\Booking\Repository\BookingRepositoryInterface;
use App\Domain\Booking\ValueObject\BookingId;
use App\Domain\Booking\ValueObject\Money;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/bookings')]
class BookingController extends AbstractController
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookingRepository
    ) {}
    
    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $id = $this->bookingRepository->nextIdentity();
        
        $booking = Booking::create(
            id: $id,
            customerId: $data['customer_id'],
            totalAmount: Money::fromAmount($data['amount'], $data['currency'] ?? 'EUR'),
            reference: $data['reference'] ?? null
        );
        
        $this->bookingRepository->save($booking);
        
        return $this->json(['id' => $id->value()], 201);
    }
    
    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $booking = $this->bookingRepository->findById(BookingId::fromString($id));
        
        if (!$booking) {
            return $this->json(['error' => 'Not found'], 404);
        }
        
        return $this->json([
            'id' => $booking->getId()->value(),
            'customer_id' => $booking->getCustomerId(),
            'amount' => $booking->getTotalAmount()->amount(),
            'currency' => $booking->getTotalAmount()->currency(),
            'status' => $booking->getStatus()->value(),
            'created_at' => $booking->getCreatedAt()->format('Y-m-d H:i:s'),
        ]);
    }
    
    #[Route('/{id}/confirm', methods: ['POST'])]
    public function confirm(string $id): JsonResponse
    {
        $booking = $this->bookingRepository->findById(BookingId::fromString($id));
        
        if (!$booking) {
            return $this->json(['error' => 'Not found'], 404);
        }
        
        $booking->confirm();
        $this->bookingRepository->save($booking);
        
        return $this->json(['status' => 'confirmed']);
    }
}
```

## Structure finale

```
src/
├── Domain/
│   └── Booking/
│       ├── Entity/
│       │   └── Booking.php
│       ├── ValueObject/
│       │   ├── BookingId.php
│       │   ├── BookingStatus.php
│       │   └── Money.php
│       └── Repository/
│           └── BookingRepositoryInterface.php
│
├── Infrastructure/
│   └── Persistence/
│       ├── Doctrine/
│       │   ├── Entity/
│       │   │   └── BookingEntity.php
│       │   └── Repository/
│       │       └── DoctrineBookingRepository.php
│       └── Mapper/
│           └── BookingMapper.php
│
└── Controller/
    └── BookingController.php
```

C'est tout ! **9 fichiers** pour une architecture DDD complète et fonctionnelle.[2][1]

[1](https://dev.to/mykola_vantukh/ddd-in-symfony-7-clean-architecture-and-deptrac-enforced-boundaries-120a)
[2](https://sensiolabs.com/blog/2025/applying-domain-driven-design-in-php-and-symfony-a-hands-on-guide)
