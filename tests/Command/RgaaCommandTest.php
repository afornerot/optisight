<?php

namespace App\Tests\Command;

use App\Command\RgaaCommand;
use App\Entity\Analysis;
use App\Entity\Site;
use App\Service\LighthouseService;
use App\Service\Pa11yService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Sans purge périodique, les rapports Lighthouse accumulés épuisent la mémoire
 * de PHP et l'analyse reste bloquée sur « running ».
 */
class RgaaCommandTest extends TestCase
{
    public function testItPurgesTheEntityManagerWhileWalkingThePages(): void
    {
        $site = new Site();
        $site->setName('Xolo DEV');
        $site->setRootUrl('https://xolo.test');
        $site->setCrawlMetadata(array_map(
            static fn (int $i): array => ['url' => 'https://xolo.test/page-' . $i, 'status' => 200, 'depth' => 0],
            range(1, 45)
        ));

        $analysis = new Analysis();
        $analysis->setSite($site);
        $analysis->setStatus(Analysis::STATUS_PENDING);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturn($this->query());
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($analysis);
        $em->method('getRepository')->willReturn($repository);
        $em->expects($this->exactly(2))->method('clear');

        $lighthouse = $this->createMock(LighthouseService::class);
        $lighthouse->method('analyze')->willReturn(null);
        $pa11y = $this->createMock(Pa11yService::class);
        $pa11y->method('analyze')->willReturn(null);

        $tester = new CommandTester(new RgaaCommand($em, $lighthouse, $pa11y, new NullLogger()));
        $tester->execute(['analysis_id' => '7']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('DONE:45 page(s) analysée(s).', $tester->getDisplay());
        $this->assertSame(Analysis::STATUS_COMPLETED, $analysis->getStatus());
    }

    private function query(): Query
    {
        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('execute')->willReturn(0);

        return $query;
    }
}
