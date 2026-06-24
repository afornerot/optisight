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

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $prodUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $authType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $authCookies = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $authLoginUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authUsernameField = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authPasswordField = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authUsername = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authPassword = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $crawlStatus = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCrawledAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $crawledPages = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $crawlMetadata = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $excludePatterns = null;

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

    public function getProdUrl(): ?string
    {
        return $this->prodUrl;
    }

    public function setProdUrl(?string $prodUrl): static
    {
        $this->prodUrl = $prodUrl;
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

    public function getAuthType(): ?string
    {
        return $this->authType;
    }

    public function setAuthType(?string $authType): static
    {
        $this->authType = $authType;
        return $this;
    }

    public function getAuthCookies(): ?string
    {
        return $this->authCookies;
    }

    public function setAuthCookies(?string $authCookies): static
    {
        $this->authCookies = $authCookies;
        return $this;
    }

    public function getAuthLoginUrl(): ?string
    {
        return $this->authLoginUrl;
    }

    public function setAuthLoginUrl(?string $authLoginUrl): static
    {
        $this->authLoginUrl = $authLoginUrl;
        return $this;
    }

    public function getAuthUsernameField(): ?string
    {
        return $this->authUsernameField;
    }

    public function setAuthUsernameField(?string $authUsernameField): static
    {
        $this->authUsernameField = $authUsernameField;
        return $this;
    }

    public function getAuthPasswordField(): ?string
    {
        return $this->authPasswordField;
    }

    public function setAuthPasswordField(?string $authPasswordField): static
    {
        $this->authPasswordField = $authPasswordField;
        return $this;
    }

    public function getAuthUsername(): ?string
    {
        return $this->authUsername;
    }

    public function setAuthUsername(?string $authUsername): static
    {
        $this->authUsername = $authUsername;
        return $this;
    }

    public function getAuthPassword(): ?string
    {
        return $this->authPassword;
    }

    public function setAuthPassword(?string $authPassword): static
    {
        $this->authPassword = $authPassword;
        return $this;
    }

    /**
     * Get cookies as array of [{name, value, domain, path, ...}] or null.
     */
    public function getAuthCookiesArray(): ?array
    {
        if ($this->authCookies === null || $this->authCookies === '') {
            return null;
        }
        $decoded = json_decode($this->authCookies, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get cookie header string suitable for HTTP requests (e.g. "name1=value1; name2=value2").
     */
    public function getCookieHeader(): ?string
    {
        $cookies = $this->getAuthCookiesArray();
        if (!$cookies) {
            return null;
        }
        $parts = [];
        foreach ($cookies as $cookie) {
            if (isset($cookie['name'], $cookie['value'])) {
                $parts[] = $cookie['name'] . '=' . $cookie['value'];
            }
        }
        return !empty($parts) ? implode('; ', $parts) : null;
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

    public function getExcludePatterns(): ?array
    {
        return $this->excludePatterns;
    }

    public function setExcludePatterns(?array $excludePatterns): static
    {
        $this->excludePatterns = $excludePatterns;
        return $this;
    }

    /**
     * Check if a URL matches any exclude pattern.
     * Each pattern: {type: "starts_with|contains|ends_with|equals", value: string}
     */
    public function isUrlExcluded(string $url): bool
    {
        if (empty($this->excludePatterns)) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $path = rtrim($path, '/');
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        $pathWithFragment = $fragment !== null && $fragment !== '' ? $path . '/#' . $fragment : $path;

        foreach ($this->excludePatterns as $pattern) {
            $type = $pattern['type'] ?? '';
            $value = $pattern['value'] ?? '';
            if ($value === '') {
                continue;
            }

            $value = str_replace('\\', '', $value);

            switch ($type) {
                case 'starts_with':
                    if (str_starts_with($pathWithFragment, $value)) {
                        return true;
                    }
                    break;
                case 'contains':
                    if (str_contains($pathWithFragment, $value)) {
                        return true;
                    }
                    break;
                case 'ends_with':
                    if (str_ends_with($pathWithFragment, $value)) {
                        return true;
                    }
                    break;
                case 'equals':
                    if ($pathWithFragment === $value) {
                        return true;
                    }
                    break;
            }
        }

        return false;
    }
}
