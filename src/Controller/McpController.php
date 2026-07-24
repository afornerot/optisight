<?php

namespace App\Controller;

use App\Mcp\Resources\SiteMcpResource;
use App\Mcp\Resources\AuditMcpResource;
use App\Mcp\Resources\PageMcpResource;
use App\Mcp\Resources\AiMcpResource;
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

#[Route('/mcp')]
class McpController extends AbstractController
{
    private McpServer $server;
    private McpAuthenticator $authenticator;

    public function __construct(
        SiteMcpResource $siteResource,
        AuditMcpResource $auditResource,
        PageMcpResource $pageResource,
        AiMcpResource $aiResource,
        McpAuthenticator $authenticator,
        McpApiKeyRepository $apiKeyRepository,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        $this->authenticator = $authenticator;
        $this->server = new McpServer($authenticator, $logger);

        $this->registerTools($siteResource, $auditResource, $pageResource, $aiResource, $apiKeyRepository, $em);
    }

    private function registerTools(
        SiteMcpResource $siteResource,
        AuditMcpResource $auditResource,
        PageMcpResource $pageResource,
        AiMcpResource $aiResource,
        McpApiKeyRepository $apiKeyRepository,
        EntityManagerInterface $em
    ): void {
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
    }

    #[Route('', name: 'mcp_handler', methods: ['POST'])]
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

    #[Route('/tools', name: 'mcp_tools', methods: ['GET'])]
    public function tools(): Response
    {
        $result = $this->server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        return new JsonResponse($result);
    }

    #[Route('/initialize', name: 'mcp_initialize', methods: ['POST'])]
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
