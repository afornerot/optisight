<?php

namespace App\Entity;

use App\Repository\AnalysisRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalysisRepository::class)]
#[ORM\Table(name: 'audit_analysis')]
class Analysis
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class, inversedBy: 'analyses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Site $site = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::INTEGER)]
    private int $pagesCrawled = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $totalPages = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, PageReport> */
    #[ORM\OneToMany(targetEntity: PageReport::class, mappedBy: 'analysis', orphanRemoval: true)]
    private Collection $pageReports;

    /** @var Collection<int, AiSummary> */
    #[ORM\OneToMany(targetEntity: AiSummary::class, mappedBy: 'analysis', orphanRemoval: true)]
    private Collection $aiSummaries;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->pageReports = new ArrayCollection();
        $this->aiSummaries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): static
    {
        $this->site = $site;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getPagesCrawled(): int
    {
        return $this->pagesCrawled;
    }

    public function setPagesCrawled(int $pagesCrawled): static
    {
        $this->pagesCrawled = $pagesCrawled;
        return $this;
    }

    public function getTotalPages(): int
    {
        return $this->totalPages;
    }

    public function setTotalPages(int $totalPages): static
    {
        $this->totalPages = $totalPages;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, PageReport> */
    public function getPageReports(): Collection
    {
        return $this->pageReports;
    }

    public function addPageReport(PageReport $pageReport): static
    {
        if (!$this->pageReports->contains($pageReport)) {
            $this->pageReports->add($pageReport);
            $pageReport->setAnalysis($this);
        }

        return $this;
    }

    public function removePageReport(PageReport $pageReport): static
    {
        if ($this->pageReports->removeElement($pageReport)) {
            if ($pageReport->getAnalysis() === $this) {
                $pageReport->setAnalysis(null);
            }
        }

        return $this;
    }

    /** @return Collection<int, AiSummary> */
    public function getAiSummaries(): Collection
    {
        return $this->aiSummaries;
    }

    public function addAiSummary(AiSummary $aiSummary): static
    {
        if (!$this->aiSummaries->contains($aiSummary)) {
            $this->aiSummaries->add($aiSummary);
            $aiSummary->setAnalysis($this);
        }

        return $this;
    }

    public function getDuration(): ?string
    {
        if ($this->startedAt && $this->completedAt) {
            $diff = $this->startedAt->diff($this->completedAt);
            return sprintf('%dm%ds', $diff->i, $diff->s);
        }
        return null;
    }

    public function getProgress(): int
    {
        if ($this->totalPages === 0) {
            return 0;
        }
        return (int) round(($this->pagesCrawled / $this->totalPages) * 100);
    }
}
