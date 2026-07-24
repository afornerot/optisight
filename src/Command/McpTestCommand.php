<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Mcp\Security\McpAuthenticator;
use App\Mcp\Resources\SiteMcpResource;
use App\Mcp\Resources\AuditMcpResource;
use App\Mcp\Resources\PageMcpResource;
use App\Repository\McpApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;

#[AsCommand(name: 'app:mcp-test', description: 'Test MCP functionality')]
class McpTestCommand extends Command
{
    public function __construct(
        private McpAuthenticator $authenticator,
        private SiteMcpResource $siteResource,
        private AuditMcpResource $auditResource,
        private PageMcpResource $pageResource,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = '40ac86f29b04523c7eabccb8c392efb8f096f50ad47f259a8d6ed5ecf44b2927';
        
        $apiKey = $this->authenticator->authenticate($token);
        if (!$apiKey) {
            $output->writeln('<error>Auth FAILED</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Auth OK: ' . $apiKey->getName() . '</info>');
        $output->writeln('');

        $output->writeln('=== Sites List ===');
        $result = $this->siteResource->list([], $apiKey);
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $output->writeln('');

        $output->writeln('=== Audit Get (id=13) ===');
        $result = $this->auditResource->get(['auditId' => 13], $apiKey);
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $output->writeln('');

        $output->writeln('=== Pages List (auditId=13) ===');
        $result = $this->pageResource->list(['auditId' => 13], $apiKey);
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $output->writeln('');

        $output->writeln('=== Page Get (id=185) ===');
        $result = $this->pageResource->get(['pageId' => 185], $apiKey);
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $output->writeln('');

        $output->writeln('=== Page AI Analyze (id=185) ===');
        $result = $this->pageResource->aiAnalyze(['pageId' => 185], $apiKey);
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return Command::SUCCESS;
    }
}
