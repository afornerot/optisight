<?php

namespace App\Mcp\Server;

use App\Mcp\Security\McpAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class McpServer
{
    private McpAuthenticator $authenticator;
    private LoggerInterface $logger;
    private array $tools = [];
    private array $resources = [];
    private array $prompts = [];

    public function __construct(
        McpAuthenticator $authenticator,
        LoggerInterface $logger
    ) {
        $this->authenticator = $authenticator;
        $this->logger = $logger;
    }

    public function registerTool(string $name, callable $handler, array $schema): void
    {
        $this->tools[$name] = [
            'handler' => $handler,
            'schema' => $schema,
        ];
    }

    public function registerResource(string $uri, callable $handler, string $mimeType = 'application/json'): void
    {
        $this->resources[$uri] = [
            'handler' => $handler,
            'mimeType' => $mimeType,
        ];
    }

    public function handleRequest(array $request, ?string $apiKey = null): array
    {
        $response = [
            'jsonrpc' => '2.0',
        ];

        if (!isset($request['id'])) {
            $response['error'] = ['code' => -32600, 'message' => 'Invalid Request'];
            return $response;
        }

        $response['id'] = $request['id'];
        $method = $request['method'] ?? '';

        try {
            switch ($method) {
                case 'initialize':
                    $response['result'] = $this->handleInitialize($request['params'] ?? []);
                    break;

                case 'tools/list':
                    $response['result'] = $this->handleToolsList();
                    break;

                case 'tools/call':
                    $response['result'] = $this->handleToolCall($request['params'] ?? [], $apiKey);
                    break;

                case 'resources/list':
                    $response['result'] = $this->handleResourcesList();
                    break;

                case 'resources/read':
                    $response['result'] = $this->handleResourceRead($request['params'] ?? [], $apiKey);
                    break;

                case 'ping':
                    $response['result'] = ['pong' => true];
                    break;

                default:
                    $response['error'] = ['code' => -32601, 'message' => 'Method not found'];
            }
        } catch (McpAccessDeniedException $e) {
            $response['error'] = ['code' => -32000, 'message' => 'Access denied: ' . $e->getMessage()];
        } catch (\Exception $e) {
            $this->logger->error('MCP error', ['method' => $method, 'error' => $e->getMessage()]);
            $response['error'] = ['code' => -32001, 'message' => 'Internal error: ' . $e->getMessage()];
        }

        return $response;
    }

    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => true],
                'resources' => ['subscribe' => false, 'listChanged' => true],
            ],
            'serverInfo' => [
                'name' => 'optisight-mcp',
                'version' => '1.0.0',
            ],
        ];
    }

    private function handleToolsList(): array
    {
        $tools = [];
        foreach ($this->tools as $name => $config) {
            $tools[] = [
                'name' => $name,
                'description' => $config['schema']['description'] ?? '',
                'inputSchema' => $config['schema']['inputSchema'] ?? ['type' => 'object'],
            ];
        }
        return ['tools' => $tools];
    }

    private function handleToolCall(array $params, ?string $apiKey): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$name])) {
            throw new \Exception("Tool not found: {$name}");
        }

        $handler = $this->tools[$name]['handler'];
        $result = $handler($arguments, $apiKey);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => is_array($result) ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $result,
                ],
            ],
            'isError' => false,
        ];
    }

    private function handleResourcesList(): array
    {
        $resources = [];
        foreach ($this->resources as $uri => $config) {
            $resources[] = [
                'uri' => $uri,
                'mimeType' => $config['mimeType'],
            ];
        }
        return ['resources' => $resources];
    }

    private function handleResourceRead(array $params, ?string $apiKey): array
    {
        $uri = $params['uri'] ?? '';

        if (!isset($this->resources[$uri])) {
            throw new \Exception("Resource not found: {$uri}");
        }

        $handler = $this->resources[$uri]['handler'];
        $data = $handler($apiKey);

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => $this->resources[$uri]['mimeType'],
                    'text' => is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string) $data,
                ],
            ],
        ];
    }

    public function handleStdin(): void
    {
        $input = fgets(STDIN);
        if ($input === false) {
            return;
        }

        $request = json_decode($input, true);
        if (!is_array($request)) {
            return;
        }

        $response = $this->handleRequest($request);
        fwrite(STDOUT, json_encode($response) . "\n");
    }

    public function handleHttp(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return [
                'status' => 400,
                'body' => ['error' => 'Invalid JSON'],
            ];
        }

        $apiKey = $request->headers->get('Authorization', '');
        if (str_starts_with($apiKey, 'Bearer ')) {
            $apiKey = substr($apiKey, 7);
        }

        $response = $this->handleRequest($data, $apiKey ?: null);

        return [
            'status' => isset($response['error']) ? 400 : 200,
            'body' => $response,
        ];
    }
}
