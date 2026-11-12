<?php

namespace App\Tests\Mcp;

use App\Controller\McpController;
use App\Mcp\Resources\AiMcpResource;
use App\Mcp\Resources\AuditMcpResource;
use App\Mcp\Resources\CrawlMcpResource;
use App\Mcp\Resources\GuideMcpResource;
use App\Mcp\Resources\PageMcpResource;
use App\Mcp\Resources\SiteMcpResource;
use App\Mcp\Security\McpAuthenticator;
use App\Repository\McpApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * tools/list suit l'ordre d'enregistrement : un agent qui decouvre la surface
 * doit voir le guide avant les outils qu'il decrit.
 */
class McpToolsOrderTest extends TestCase
{
    public function testTheGuideIsListedFirstAndHealthIsExposed(): void
    {
        $controller = new McpController(
            $this->createStub(SiteMcpResource::class),
            $this->createStub(AuditMcpResource::class),
            $this->createStub(PageMcpResource::class),
            $this->createStub(AiMcpResource::class),
            $this->createStub(CrawlMcpResource::class),
            $this->createStub(GuideMcpResource::class),
            $this->createStub(McpAuthenticator::class),
            $this->createStub(McpApiKeyRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );

        $payload = json_decode((string) $controller->tools()->getContent(), true);
        $names = array_column($payload['result']['tools'], 'name');

        $this->assertSame('optisight.guide', $names[0]);
        $this->assertContains('optisight.audit.health', $names);
    }
}
