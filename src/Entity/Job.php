<?php declare(strict_types=1);

namespace Packeton\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: 'Packeton\Repository\JobRepository')]
#[ORM\Table(name: 'job')]
#[ORM\Index(columns: ['type'], name: 'type_idx')]
#[ORM\Index(columns: ['status'], name: 'status_idx')]
#[ORM\Index(columns: ['executeAfter'], name: 'execute_dt_idx')]
#[ORM\Index(columns: ['createdAt'], name: 'creation_idx')]
#[ORM\Index(columns: ['completedAt'], name: 'completion_idx')]
#[ORM\Index(columns: ['startedAt'], name: 'started_idx')]
#[ORM\Index(columns: ['packageId'], name: 'package_id_idx')]
class Job
{
    const STATUS_QUEUED = 'queued';
    const STATUS_STARTED = 'started';
    const STATUS_COMPLETED = 'completed';
    const STATUS_PACKAGE_GONE = 'package_gone';
    const STATUS_PACKAGE_DELETED = 'package_deleted';
    const STATUS_FAILED = 'failed'; // failed in an expected/correct way
    const STATUS_ERRORED = 'errored'; // unexpected failure
    const STATUS_TIMEOUT = 'timeout'; // job was marked timed out
    const STATUS_RESCHEDULE = 'reschedule';

    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private ?string $id = null;

    #[ORM\Column(type: 'string')]
    private ?string $type = null;

    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\Column(type: 'string')]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = null;

    #[ORM\Column(name: 'createdat', type: 'datetime')]
    private \DateTimeInterface|null $createdAt = null;

    #[ORM\Column(name: 'startedat', type: 'datetime', nullable: true)]
    private \DateTimeInterface|null $startedAt = null;

    #[ORM\Column(name: 'completedat', type: 'datetime', nullable: true)]
    private \DateTimeInterface|null $completedAt = null;

    #[ORM\Column(name: 'executeafter', type: 'datetime', nullable: true)]
    private \DateTimeInterface|null $executeAfter = null;

    #[ORM\Column(name: 'packageid', type: 'integer', nullable: true)]
    private ?int $packageId = null;

    public function start()
    {
        $this->startedAt = new \DateTime();
        $this->status = self::STATUS_STARTED;
    }

    public function complete(array $result)
    {
        $this->result = $result;
        $this->completedAt = new \DateTime();
        $this->status = $result['status'];
    }

    public function reschedule(\DateTimeInterface $when)
    {
        $this->status = self::STATUS_QUEUED;
        $this->startedAt = null;
        $this->setExecuteAfter($when);
    }

    public function setId(string $id)
    {
        $this->id = $id;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setPackageId(?int $packageId)
    {
        $this->packageId = $packageId;
    }

    public function getPackageId()
    {
        return $this->packageId;
    }

    public function setType(string $type)
    {
        $this->type = $type;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setPayload(array $payload)
    {
        $this->payload = $payload;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setStatus(string $status)
    {
        $this->status = $status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isErrored(): bool
    {
        return in_array($this->status, [self::STATUS_ERRORED, self::STATUS_FAILED], true);
    }

    public function getResult(?string $property = null)
    {
        return $property ? ($this->result[$property] ?? null) : $this->result;
    }

    public function setCreatedAt(DateTimeInterface $createdAt)
    {
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setExecuteAfter(DateTimeInterface $executeAfter)
    {
        $this->executeAfter = $executeAfter;
    }

    public function getExecuteAfter(): ?DateTimeInterface
    {
        return $this->executeAfter;
    }

    public function getCompletedAt(): ?DateTimeInterface
    {
        return $this->completedAt;
    }
}
