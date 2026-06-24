<?php

namespace App\Entity;

use App\Repository\AiSummaryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AiSummaryRepository::class)]
#[ORM\Table(name: 'audit_ai_summary')]
class AiSummary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Analysis::class, inversedBy: 'aiSummaries')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Analysis $analysis = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $recommendations = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $summaryJson = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnalysis(): ?Analysis
    {
        return $this->analysis;
    }

    public function setAnalysis(?Analysis $analysis): static
    {
        $this->analysis = $analysis;
        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;
        return $this;
    }

    public function getRecommendations(): ?array
    {
        return $this->recommendations;
    }

    public function setRecommendations(?array $recommendations): static
    {
        $this->recommendations = $recommendations;
        return $this;
    }

    public function getSummaryJson(): ?array
    {
        return $this->summaryJson;
    }

    public function setSummaryJson(?array $summaryJson): static
    {
        $this->summaryJson = $summaryJson;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
