<?php

namespace App\Tests\Service;

use App\Service\InspectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * InspectService enveloppe bin/inspect.js (Puppeteer) derrière un process node.
 * Le Process est instancié en dur : on ne peut pas le mocker, on remplace donc
 * le script par un faux — placé dans un projectDir temporaire — qui rejoue ce
 * que le vrai rend : argv sur la ligne de commande, un JSON sur stdout, et un
 * JSON d'erreur sur stderr avec un code de sortie 1.
 */
class InspectServiceTest extends TestCase
{
    private const FAKE_SCRIPT = <<<'JS'
const args = process.argv.slice(2);
const mode = process.env.OPTISIGHT_INSPECT_TEST_MODE || 'ok';

if (mode === 'empty') {
    process.exit(0);
}
if (mode === 'invalid') {
    process.stdout.write('not json at all');
    process.exit(0);
}
if (mode === 'error') {
    console.error(JSON.stringify({ error: 'boom: browser exploded' }));
    process.exit(1);
}

const getArg = (name) => {
    const i = args.indexOf('--' + name);
    return i !== -1 && i + 1 < args.length ? args[i + 1] : null;
};

console.log(JSON.stringify({
    argv: args,
    url: getArg('url'),
    selector: getArg('selector'),
    wait: getArg('wait'),
    viewport: getArg('viewport'),
    cookie: getArg('cookie'),
    found: true,
    node: { tag: 'span', selector: 'span.badge', snippet: '<span class="badge">Neuf</span>', text: 'Neuf', role: null },
    styles: {
        color: '179,179,179',
        renderedColor: '222,222,222',
        background: '255,255,255',
        effectiveOpacity: 0.6,
        fontSize: '14px',
        fontWeight: '400',
        display: 'inline',
        visibility: 'visible',
    },
    contrastRatio: 1.32,
    targetSize: { width: 24.0, height: 18.5 },
    visible: true,
}, null, 2));
JS;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/optisight_inspect_' . uniqid();
        mkdir($this->projectDir . '/bin', 0777, true);
        file_put_contents($this->projectDir . '/bin/inspect.js', self::FAKE_SCRIPT);
        unset($_ENV['OPTISIGHT_INSPECT_TEST_MODE']);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir . '/bin/inspect.js');
        @rmdir($this->projectDir . '/bin');
        @rmdir($this->projectDir);
        unset($_ENV['OPTISIGHT_INSPECT_TEST_MODE']);
    }

    public function testItBuildsTheCommandLineWithUrlSelectorWaitAndViewport(): void
    {
        $result = $this->inspect('https://xolo.test/usage', 'span.badge', null, 2500, '1440x900');

        self::assertNotNull($result);
        self::assertSame(
            [
                '--url', 'https://xolo.test/usage',
                '--selector', 'span.badge',
                '--wait', '2500',
                '--viewport', '1440x900',
            ],
            $result['argv']
        );
    }

    public function testItPassesTheCookieHeaderWhenGiven(): void
    {
        $result = $this->inspect('https://xolo.test/admin', '.menu__item', 'xolo_session=abc; csrf=def');

        self::assertNotNull($result);
        self::assertIsArray($result['argv']);
        self::assertContains('--cookie', $result['argv']);
        self::assertContains('xolo_session=abc; csrf=def', $result['argv']);
    }

    public function testItOmitsTheCookieArgumentWithoutACookieHeader(): void
    {
        $result = $this->inspect('https://xolo.test/', 'h1');

        self::assertNotNull($result);
        self::assertIsArray($result['argv']);
        self::assertNotContains('--cookie', $result['argv']);
    }

    public function testItReturnsTheDecodedPayload(): void
    {
        $result = $this->inspect('https://xolo.test/usage', 'span.badge');

        self::assertNotNull($result);
        self::assertTrue($result['found']);
        self::assertSame(1.32, $result['contrastRatio']);
        self::assertArrayNotHasKey('error', $result);

        self::assertIsArray($result['node']);
        self::assertSame('span', $result['node']['tag']);

        self::assertIsArray($result['styles']);
        self::assertSame(0.6, $result['styles']['effectiveOpacity']);
    }

    public function testItReturnsTheScriptErrorWhenTheScriptFails(): void
    {
        $_ENV['OPTISIGHT_INSPECT_TEST_MODE'] = 'error';

        $result = $this->inspect('https://xolo.test/', 'nav a');

        self::assertNotNull($result);
        self::assertSame('boom: browser exploded', $result['error']);
    }

    public function testItReturnsNullWhenTheOutputIsNotJson(): void
    {
        $_ENV['OPTISIGHT_INSPECT_TEST_MODE'] = 'invalid';

        self::assertNull($this->inspect('https://xolo.test/', 'h1'));
    }

    public function testItReturnsNullWhenTheOutputIsEmpty(): void
    {
        $_ENV['OPTISIGHT_INSPECT_TEST_MODE'] = 'empty';

        self::assertNull($this->inspect('https://xolo.test/', 'h1'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspect(
        string $url,
        string $selector,
        ?string $cookieHeader = null,
        int $waitMs = 1500,
        string $viewport = '1280x900'
    ): ?array {
        $service = new InspectService(new NullLogger(), $this->projectDir);

        return $service->inspect($url, $selector, $cookieHeader, $waitMs, $viewport);
    }
}
