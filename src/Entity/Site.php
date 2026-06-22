<?php

namespace App\Entity;

use App\Repository\SiteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: 'audit_site')]
class Site
{
    public const CRAWL_STATUS_RUNNING = 'running';
    public const CRAWL_STATUS_COMPLETED = 'completed';
    public const CRAWL_STATUS_FAILED = 'failed';
    public const CRAWL_STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private ?string $rootUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $crawlStatus = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCrawledAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $crawledPages = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $crawlMetadata = null;

    /** @var Collection<int, Analysis> */
    #[ORM\OneToMany(targetEntity: Analysis::class, mappedBy: 'site', orphanRemoval: true)]
    private Collection $analyses;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->analyses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getRootUrl(): ?string
    {
        return $this->rootUrl;
    }

    public function setRootUrl(string $rootUrl): static
    {
        $this->rootUrl = $rootUrl;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /** @return Collection<int, Analysis> */
    public function getAnalyses(): Collection
    {
        return $this->analyses;
    }

    public function addAnalysis(Analysis $analysis): static
    {
        if (!$this->analyses->contains($analysis)) {
            $this->analyses->add($analysis);
            $analysis->setSite($this);
        }

        return $this;
    }

    public function removeAnalysis(Analysis $analysis): static
    {
        if ($this->analyses->removeElement($analysis)) {
            if ($analysis->getSite() === $this) {
                $analysis->setSite(null);
            }
        }

        return $this;
    }

    public function getLastAnalysis(): ?Analysis
    {
        return $this->analyses->isEmpty() ? null : $this->analyses->first();
    }

    public function getCrawlStatus(): ?string
    {
        return $this->crawlStatus;
    }

    public function setCrawlStatus(?string $crawlStatus): static
    {
        $this->crawlStatus = $crawlStatus;
        return $this;
    }

    public function getLastCrawledAt(): ?\DateTimeImmutable
    {
        return $this->lastCrawledAt;
    }

    public function setLastCrawledAt(?\DateTimeImmutable $lastCrawledAt): static
    {
        $this->lastCrawledAt = $lastCrawledAt;
        return $this;
    }

    public function getCrawledPages(): int
    {
        return $this->crawledPages;
    }

    public function setCrawledPages(int $crawledPages): static
    {
        $this->crawledPages = $crawledPages;
        return $this;
    }

    public function getCrawlMetadata(): ?array
    {
        return $this->crawlMetadata;
    }

    public function setCrawlMetadata(?array $crawlMetadata): static
    {
        $this->crawlMetadata = $crawlMetadata;
        return $this;
    }
}
