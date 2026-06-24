<?php

namespace App\Service;

use App\Entity\Site;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class AuthService
{
    private LoggerInterface $logger;
    private string $projectDir;

    public function __construct(LoggerInterface $logger, string $projectDir)
    {
        $this->logger = $logger;
        $this->projectDir = $projectDir;
    }

    /**
     * Run Puppeteer login and return cookies.
     *
     * @return array{success: bool, cookies: array, error: ?string, finalUrl: ?string}
     */
    public function loginWithForm(Site $site): array
    {
        $loginUrl = $site->getAuthLoginUrl();
        $usernameField = $site->getAuthUsernameField();
        $passwordField = $site->getAuthPasswordField();
        $username = $site->getAuthUsername();
        $password = $site->getAuthPassword();

        if (!$loginUrl || !$usernameField || !$passwordField || !$username || !$password) {
            return [
                'success' => false,
                'cookies' => [],
                'error' => 'Champs de connexion incomplets.',
                'finalUrl' => null,
            ];
        }

        $scriptPath = $this->projectDir . '/bin/auth-login.js';

        $process = new Process([
            'node', $scriptPath,
            '--login-url', $loginUrl,
            '--username-field', $usernameField,
            '--password-field', $passwordField,
            '--username', $username,
            '--password', $password,
        ]);
        $process->setTimeout(60);
        $process->setEnv(array_merge($_ENV, [
            'NODE_PATH' => '/usr/local/lib/node_modules',
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
        ]));
        $process->run();

        $output = $process->getOutput() ?: $process->getErrorOutput();

        if (empty($output)) {
            $this->logger->error('Puppeteer login: empty output', [
                'error' => $process->getErrorOutput(),
            ]);
            return [
                'success' => false,
                'cookies' => [],
                'error' => 'Aucune réponse du script de login.',
                'finalUrl' => null,
            ];
        }

        $result = json_decode(trim($output), true);

        if (!is_array($result)) {
            $this->logger->error('Puppeteer login: invalid JSON output', ['output' => $output]);
            return [
                'success' => false,
                'cookies' => [],
                'error' => 'Réponse invalide du script de login.',
                'finalUrl' => null,
            ];
        }

        $this->logger->info('Puppeteer login result', [
            'success' => $result['success'] ?? false,
            'cookies_count' => count($result['cookies'] ?? []),
            'finalUrl' => $result['finalUrl'] ?? null,
        ]);

        return $result;
    }

    /**
     * Test if a cookie header is valid for the site.
     */
    public function testCookie(Site $site): array
    {
        $cookieHeader = $site->getCookieHeader();
        if ($cookieHeader === null) {
            return [
                'success' => false,
                'error' => 'Aucun cookie configuré.',
                'statusCode' => null,
            ];
        }

        try {
            $process = new Process([
                'node', '-e',
                'const http = require("http");'.
                'const https = require("https");'.
                'const url = new URL(process.argv[1]);'.
                'const mod = url.protocol === "https:" ? https : http;'.
                'const req = mod.get(url.href, {'.
                '  headers: { Cookie: process.argv[2], "User-Agent": "iargaaseo-test/1.0" },'.
                '  timeout: 10000,'.
                '}, res => {'.
                '  process.stdout.write(JSON.stringify({ success: res.statusCode < 400, statusCode: res.statusCode }));'.
                '  res.resume();'.
                '});'.
                'req.on("error", e => process.stdout.write(JSON.stringify({ success: false, error: e.message, statusCode: null })));',
                $site->getRootUrl(),
                $cookieHeader,
            ]);
            $process->setTimeout(15);
            $process->run();

            $output = trim($process->getOutput());
            $result = json_decode($output, true);

            return $result ?: ['success' => false, 'error' => 'Réponse invalide', 'statusCode' => null];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'statusCode' => null,
            ];
        }
    }
}
