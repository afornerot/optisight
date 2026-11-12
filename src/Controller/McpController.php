<?php

namespace App\Controller;

use App\Mcp\Resources\SiteMcpResource;
use App\Mcp\Resources\AuditMcpResource;
use App\Mcp\Resources\PageMcpResource;
use App\Mcp\Resources\AiMcpResource;
use App\Mcp\Resources\CrawlMcpResource;
use App\Mcp\Resources\GuideMcpResource;
use App\Mcp\Security\McpAuthenticator;
use App\Mcp\Server\McpServer;
use App\Repository\McpApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[\Symfony\Component\Routing\Attribute\Route('/mcp')]
class McpController extends AbstractController
{
    private McpServer $server;
    private McpAuthenticator $authenticator;

    public function __construct(
        SiteMcpResource $siteResource,
        AuditMcpResource $auditResource,
        PageMcpResource $pageResource,
        AiMcpResource $aiResource,
        CrawlMcpResource $crawlResource,
        GuideMcpResource $guideResource,
        McpAuthenticator $authenticator,
        McpApiKeyRepository $apiKeyRepository,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        $this->authenticator = $authenticator;
        $this->server = new McpServer($authenticator, $logger);

        $this->registerTools($siteResource, $auditResource, $pageResource, $aiResource, $crawlResource, $guideResource, $apiKeyRepository, $em);
    }

    private function registerTools(
        SiteMcpResource $siteResource,
        AuditMcpResource $auditResource,
        PageMcpResource $pageResource,
        AiMcpResource $aiResource,
        CrawlMcpResource $crawlResource,
        GuideMcpResource $guideResource,
        McpApiKeyRepository $apiKeyRepository,
        EntityManagerInterface $em
    ): void {
        // ─── Guide ────────────────────────────────────────────────────────────
        // Le guide est enregistre le premier : tools/list suit l'ordre
        // d'enregistrement, et un agent qui decouvre la surface doit la voir
        // avant les outils qu'elle decrit.
        $this->server->registerTool('optisight.guide', function ($args, $token) use ($guideResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('guide')) {
                throw new \Exception('Permission denied: guide');
            }
            return $guideResource->get($args, $apiKey);
        }, [
            'description' => 'How to drive this MCP surface: the loop, the tools, the traps and the recipes. Read this first.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'topic' => ['type' => 'string', 'description' => 'Only the sections whose title contains this word'],
                ],
            ],
        ]);

        $this->server->registerTool('optisight.sites.list', function ($args, $token) use ($siteResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('sites.list')) {
                throw new \Exception('Permission denied: sites.list');
            }
            return $siteResource->list($args, $apiKey);
        }, [
            'description' => 'List all sites in Optiight',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
            ],
        ]);

        $this->server->registerTool('optisight.site.get', function ($args, $token) use ($siteResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('sites.get')) {
                throw new \Exception('Permission denied: sites.get');
            }
            return $siteResource->get($args, $apiKey);
        }, [
            'description' => 'Get details of a specific site',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'siteId' => ['type' => 'integer', 'description' => 'Site ID'],
                ],
                'required' => ['siteId'],
            ],
        ]);

        $this->server->registerTool('optisight.audits.list', function ($args, $token) use ($auditResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('audits.list')) {
                throw new \Exception('Permission denied: audits.list');
            }
            return $auditResource->list($args, $apiKey);
        }, [
            'description' => 'List audits (optionally filtered by siteId)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'siteId' => ['type' => 'integer', 'description' => 'Filter by site ID'],
                ],
            ],
        ]);

        $this->server->registerTool('optisight.audit.get', function ($args, $token) use ($auditResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('audits.get')) {
                throw new \Exception('Permission denied: audits.get');
            }
            return $auditResource->get($args, $apiKey);
        }, [
            'description' => 'Get details of a specific audit including scores',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'auditId' => ['type' => 'integer', 'description' => 'Audit ID'],
                ],
                'required' => ['auditId'],
            ],
        ]);

        $this->server->registerTool('optisight.audit.trigger', function ($args, $token) use ($auditResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('audits.trigger')) {
                throw new \Exception('Permission denied: audits.trigger');
            }
            return $auditResource->trigger($args, $apiKey);
        }, [
            'description' => 'Trigger a new audit for a site',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'siteId' => ['type' => 'integer', 'description' => 'Site ID to audit'],
                ],
                'required' => ['siteId'],
            ],
        ]);

        $this->server->registerTool('optisight.pages.list', function ($args, $token) use ($pageResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('pages.list')) {
                throw new \Exception('Permission denied: pages.list');
            }
            return $pageResource->list($args, $apiKey);
        }, [
            'description' => 'List all pages of an audit',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'auditId' => ['type' => 'integer', 'description' => 'Audit ID'],
                ],
                'required' => ['auditId'],
            ],
        ]);

        $this->server->registerTool('optisight.page.get', function ($args, $token) use ($pageResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('pages.get')) {
                throw new \Exception('Permission denied: pages.get');
            }
            return $pageResource->get($args, $apiKey);
        }, [
            'description' => 'Get detailed analysis of a specific page',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'pageId' => ['type' => 'integer', 'description' => 'Page ID'],
                ],
                'required' => ['pageId'],
            ],
        ]);

        $this->server->registerTool('optisight.page.analyze', function ($args, $token) use ($pageResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('pages.analyze')) {
                throw new \Exception('Permission denied: pages.analyze');
            }
            return $pageResource->analyze($args, $apiKey);
        }, [
            'description' => 'Re-run Lighthouse and RGAA analysis on a specific page',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'pageId' => ['type' => 'integer', 'description' => 'Page ID to re-analyze'],
                ],
                'required' => ['pageId'],
            ],
        ]);

        $this->server->registerTool('optisight.page.ai_analyze', function ($args, $token) use ($pageResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('pages.ai_analyze')) {
                throw new \Exception('Permission denied: pages.ai_analyze');
            }
            return $pageResource->aiAnalyze($args, $apiKey);
        }, [
            'description' => 'Re-run AI analysis on a specific page',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'pageId' => ['type' => 'integer', 'description' => 'Page ID to analyze with AI'],
                ],
                'required' => ['pageId'],
            ],
        ]);

        $this->server->registerTool('optisight.ai.summary', function ($args, $token) use ($aiResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('ai.summary')) {
                throw new \Exception('Permission denied: ai.summary');
            }
            return $aiResource->getSummary($args, $apiKey);
        }, [
            'description' => 'Get AI summary of an audit',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'auditId' => ['type' => 'integer', 'description' => 'Audit ID'],
                ],
                'required' => ['auditId'],
            ],
        ]);

        $this->server->registerTool('optisight.ai.trigger', function ($args, $token) use ($aiResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('ai.trigger')) {
                throw new \Exception('Permission denied: ai.trigger');
            }
            return $aiResource->trigger($args, $apiKey);
        }, [
            'description' => 'Trigger AI summary generation for an audit',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'auditId' => ['type' => 'integer', 'description' => 'Audit ID to generate AI summary for'],
                ],
                'required' => ['auditId'],
            ],
        ]);

        // ─── Crawl ────────────────────────────────────────────────────────────

        $this->server->registerTool('optisight.crawl.trigger', function ($args, $token) use ($crawlResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('crawl.trigger')) {
                throw new \Exception('Permission denied: crawl.trigger');
            }
            return $crawlResource->trigger($args, $apiKey);
        }, [
            'description' => 'Crawl a site and store its page list. An audit does NOT crawl: run this first when the site changed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'siteId' => ['type' => 'integer', 'description' => 'Site ID to crawl'],
                    'dedup' => ['type' => 'boolean', 'description' => 'Keep one page per route shape (default: the deployment setting)'],
                ],
                'required' => ['siteId'],
            ],
        ]);

        $this->server->registerTool('optisight.crawl.status', function ($args, $token) use ($crawlResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('crawl.status')) {
                throw new \Exception('Permission denied: crawl.status');
            }
            return $crawlResource->status($args, $apiKey);
        }, [
            'description' => 'Crawl state, page count, and the tail of the crawl log: every discarded URL with its reason',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'siteId' => ['type' => 'integer', 'description' => 'Site ID'],
                ],
                'required' => ['siteId'],
            ],
        ]);

        // ─── Findings, comparison, guide ──────────────────────────────────────

        $this->server->registerTool('optisight.audit.findings', function ($args, $token) use ($auditResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('audits.get')) {
                throw new \Exception('Permission denied: audits.get');
            }
            return $auditResource->findings($args, $apiKey);
        }, [
            'description' => 'Findings of an audit, grouped by node shape (the leverage view), rule or page',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'auditId' => ['type' => 'integer', 'description' => 'Audit ID'],
                    'groupBy' => ['type' => 'string', 'enum' => ['shape', 'rule', 'page'], 'description' => 'Grouping (default: shape)'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum groups returned (default: 50)'],
                ],
                'required' => ['auditId'],
            ],
        ]);

        $this->server->registerTool('optisight.audit.compare', function ($args, $token) use ($auditResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('audits.get')) {
                throw new \Exception('Permission denied: audits.get');
            }
            return $auditResource->compare($args, $apiKey);
        }, [
            'description' => 'Compare two audits: fixed, appeared, unchanged, and the score deltas',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'baseAuditId' => ['type' => 'integer', 'description' => 'Audit before the change'],
                    'headAuditId' => ['type' => 'integer', 'description' => 'Audit after the change'],
                    'threshold' => ['type' => 'integer', 'description' => 'How many new findings are tolerated (default: 0)'],
                ],
                'required' => ['baseAuditId', 'headAuditId'],
            ],
        ]);

        $this->server->registerTool('optisight.page.inspect', function ($args, $token) use ($pageResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('pages.get')) {
                throw new \Exception('Permission denied: pages.get');
            }
            return $pageResource->inspect($args, $apiKey);
        }, [
            'description' => 'Measured facts behind a finding: computed colours, effective opacity, contrast ratio, target size, accessible name',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'pageId' => ['type' => 'integer', 'description' => 'Page report ID'],
                    'selector' => ['type' => 'string', 'description' => 'CSS selector of the node'],
                    'rule' => ['type' => 'string', 'description' => 'Rule code, e.g. H91.InputNumber.Name (used with index when no selector is given)'],
                    'index' => ['type' => 'integer', 'description' => 'Which occurrence of that rule (default: 0)'],
                    'wait' => ['type' => 'integer', 'description' => 'Milliseconds to wait after load (default: 1500)'],
                ],
                'required' => ['pageId'],
            ],
        ]);

        $this->server->registerTool('optisight.audit.health', function ($args, $token) use ($auditResource) {
            $apiKey = $this->authenticator->authenticate($token);
            if (!$apiKey || !$apiKey->hasPermission('audits.get')) {
                throw new \Exception('Permission denied: audits.get');
            }
            return $auditResource->health($args, $apiKey);
        }, [
            'description' => 'Reliability verdict for a run: pages analysed against the expected count, pages missing from either engine, HTTP errors — read it before trusting a score',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'auditId' => ['type' => 'integer', 'description' => 'Audit ID'],
                ],
                'required' => ['auditId'],
            ],
        ]);
    }

    #[\Symfony\Component\Routing\Attribute\Route('', name: 'mcp_handler', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $authHeader = $request->headers->get('Authorization', '');
        $token = null;

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        }

        $result = $this->server->handleHttp($request);

        return new JsonResponse(
            $result['body'],
            $result['status']
        );
    }

    #[\Symfony\Component\Routing\Attribute\Route('/tools', name: 'mcp_tools', methods: ['GET'])]
    public function tools(): Response
    {
        $result = $this->server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        return new JsonResponse($result);
    }

    #[\Symfony\Component\Routing\Attribute\Route('/initialize', name: 'mcp_initialize', methods: ['POST'])]
    public function initialize(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $result = $this->server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => $data['id'] ?? 1,
            'method' => 'initialize',
            'params' => $data['params'] ?? [],
        ]);

        return new JsonResponse($result);
    }
}
