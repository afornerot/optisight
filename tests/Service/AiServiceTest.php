<?php

namespace App\Tests\Service;

use App\Entity\PageReport;
use App\Service\AiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Un 429 de l API de modèle partait sans reprise : toArray(false) avalait le
 * statut, le contenu était vide, et l indicateur échouait. Le statut est
 * désormais lu avant le corps et un throttling déclenche le backoff.
 */
class AiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['AI_RETRY_DELAY_MS'] = '0';
    }

    public function testItRetriesAThrottledCallThenReadsTheAnswer(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            $calls++;

            if ($calls === 1) {
                return new MockResponse('{"error":"rate limited"}', ['http_code' => 429]);
            }

            return new MockResponse(json_encode([
                'model' => 'mistral-small-latest',
                'usage' => ['total_tokens' => 42],
                'choices' => [[
                    'message' => [
                        'content' => '{"summary":"Deux phrases sur le SEO.","recommendations":[{"category":"seo","priority":"HIGH","title":"Corriger le titre"}]}',
                    ],
                ]],
            ], JSON_UNESCAPED_UNICODE));
        });

        $service = $this->service($client);

        $result = $service->analyzePageByIndicator(['url' => 'https://xolo.test/usage'], 'seo');

        $this->assertSame(2, $calls);
        $this->assertNotNull($result);
        $this->assertSame('Deux phrases sur le SEO.', $result['summary']);
        $this->assertSame('seo', $result['recommendations'][0]['category']);
    }

    public function testItGivesUpOnARejectedKeyWithoutRetrying(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            $calls++;

            return new MockResponse('{"error":"invalid api key"}', ['http_code' => 401]);
        });

        $this->assertNull($this->service($client)->analyzePageByIndicator(['url' => 'https://xolo.test'], 'seo'));
        $this->assertSame(1, $calls);
    }

    public function testItRefusesAnUnknownIndicator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service(new MockHttpClient())->analyzePageByIndicator(['url' => 'https://xolo.test'], 'inconnu');
    }

    public function testPageDataCarriesThePa11yReportAndTheRgaaErrors(): void
    {
        $report = new PageReport();
        $report->setUrl('https://xolo.test/usage');
        $report->setLhPerformance(72.0);
        $report->setRgaaScore(85.0);
        $report->setRgaaErrors([['code' => 'H91.InputNumber.Name', 'message' => 'Champ sans nom']]);
        $report->setPa11yReport([['type' => 'error', 'code' => 'H91.InputNumber.Name', 'message' => 'Champ sans nom']]);

        $data = AiService::pageData($report);

        $this->assertSame('https://xolo.test/usage', $data['url']);
        $this->assertSame(72.0, $data['lhPerformance']);
        // Sans cette clé, l indicateur RGAA répondait « aucune erreur » sur une
        // page qui en avait.
        $this->assertSame([['type' => 'error', 'code' => 'H91.InputNumber.Name', 'message' => 'Champ sans nom']], $data['pa11yReport']);
        $this->assertSame([['code' => 'H91.InputNumber.Name', 'message' => 'Champ sans nom']], $data['rgaaErrors']);
        $this->assertSame([], $data['rgaaWarnings']);
    }

    private function service(MockHttpClient $client): AiService
    {
        return new AiService($client, new NullLogger(), 'mistral-small-latest', 'real-key', 'https://ai.test/v1');
    }
}
