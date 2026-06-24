<?php

namespace App\Entity;

use App\Repository\PageReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PageReportRepository::class)]
#[ORM\Table(name: 'audit_page_report')]
class PageReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Analysis::class, inversedBy: 'pageReports')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Analysis $analysis = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $url = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $httpStatus = null;

    // Lighthouse scores (0-100)
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $lhPerformance = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $lhAccessibility = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $lhSeo = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $lhBestPractices = null;

    // RGAA
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $rgaaScore = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rgaaErrors = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rgaaWarnings = [];

    // SEO metadata
    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $seoTitle = null;

    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $seoDescription = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $seoH1Count = null;

    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $seoCanonical = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $seoOgTags = [];

    // Raw reports
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $lighthouseReport = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pa11yReport = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $crawlMetadata = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $aiAnalysis = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $aiAnalysisAt = null;

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

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function setHttpStatus(?int $httpStatus): static
    {
        $this->httpStatus = $httpStatus;
        return $this;
    }

    public function getLhPerformance(): ?float
    {
        return $this->lhPerformance;
    }

    public function setLhPerformance(?float $lhPerformance): static
    {
        $this->lhPerformance = $lhPerformance;
        return $this;
    }

    public function getLhAccessibility(): ?float
    {
        return $this->lhAccessibility;
    }

    public function setLhAccessibility(?float $lhAccessibility): static
    {
        $this->lhAccessibility = $lhAccessibility;
        return $this;
    }

    public function getLhSeo(): ?float
    {
        return $this->lhSeo;
    }

    public function setLhSeo(?float $lhSeo): static
    {
        $this->lhSeo = $lhSeo;
        return $this;
    }

    public function getLhBestPractices(): ?float
    {
        return $this->lhBestPractices;
    }

    public function setLhBestPractices(?float $lhBestPractices): static
    {
        $this->lhBestPractices = $lhBestPractices;
        return $this;
    }

    public function getRgaaScore(): ?float
    {
        return $this->rgaaScore;
    }

    public function setRgaaScore(?float $rgaaScore): static
    {
        $this->rgaaScore = $rgaaScore;
        return $this;
    }

    public function getRgaaErrors(): ?array
    {
        return $this->rgaaErrors;
    }

    public function setRgaaErrors(?array $rgaaErrors): static
    {
        $this->rgaaErrors = $rgaaErrors;
        return $this;
    }

    public function getRgaaWarnings(): ?array
    {
        return $this->rgaaWarnings;
    }

    public function setRgaaWarnings(?array $rgaaWarnings): static
    {
        $this->rgaaWarnings = $rgaaWarnings;
        return $this;
    }

    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function setSeoTitle(?string $seoTitle): static
    {
        $this->seoTitle = $seoTitle;
        return $this;
    }

    public function getSeoDescription(): ?string
    {
        return $this->seoDescription;
    }

    public function setSeoDescription(?string $seoDescription): static
    {
        $this->seoDescription = $seoDescription;
        return $this;
    }

    public function getSeoH1Count(): ?int
    {
        return $this->seoH1Count;
    }

    public function setSeoH1Count(?int $seoH1Count): static
    {
        $this->seoH1Count = $seoH1Count;
        return $this;
    }

    public function getSeoCanonical(): ?string
    {
        return $this->seoCanonical;
    }

    public function setSeoCanonical(?string $seoCanonical): static
    {
        $this->seoCanonical = $seoCanonical;
        return $this;
    }

    public function getSeoOgTags(): ?array
    {
        return $this->seoOgTags;
    }

    public function setSeoOgTags(?array $seoOgTags): static
    {
        $this->seoOgTags = $seoOgTags;
        return $this;
    }

    public function getLighthouseReport(): ?array
    {
        return $this->lighthouseReport;
    }

    public function setLighthouseReport(?array $lighthouseReport): static
    {
        $this->lighthouseReport = $lighthouseReport;
        return $this;
    }

    public function getPa11yReport(): ?array
    {
        return $this->pa11yReport;
    }

    public function setPa11yReport(?array $pa11yReport): static
    {
        $this->pa11yReport = $pa11yReport;
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

    public function getAiAnalysis(): ?array
    {
        return $this->aiAnalysis;
    }

    public function setAiAnalysis(?array $aiAnalysis): static
    {
        $this->aiAnalysis = $aiAnalysis;
        return $this;
    }

    public function getAiAnalysisAt(): ?\DateTimeImmutable
    {
        return $this->aiAnalysisAt;
    }

    public function setAiAnalysisAt(?\DateTimeImmutable $aiAnalysisAt): static
    {
        $this->aiAnalysisAt = $aiAnalysisAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
