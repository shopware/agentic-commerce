<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\AgenticFiles;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The plugin's additions to the core `llms.txt` and `AGENTS.md`: Basic AI Readiness switches the
 * files on with UCP off, and then they must not point agents at UCP.
 *
 * @internal
 */
#[CoversNothing]
final class UcpDiscoveryIncludesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function includes(): iterable
    {
        yield 'llms.txt' => ['@SwagAgenticCommerce/agentic-commerce/includes/ucp-llms.txt.twig'];
        yield 'AGENTS.md' => ['@SwagAgenticCommerce/agentic-commerce/includes/ucp-agents.md.twig'];
    }

    #[DataProvider('includes')]
    public function testNothingAboutUcpIsRenderedWhileUcpIsOff(string $template): void
    {
        static::assertSame('', trim($this->render($template, false)));
    }

    #[DataProvider('includes')]
    public function testTheUcpProfileIsAdvertisedOnceUcpIsOn(string $template): void
    {
        static::assertStringContainsString('/.well-known/ucp', $this->render($template, true));
    }

    private function render(string $template, bool $ucpActive): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../src/Resources/views', 'SwagAgenticCommerce');

        $twig = new Environment($loader);
        $twig->addFunction(new TwigFunction('swag_agentic_commerce_ucp_active', static fn (): bool => $ucpActive));

        return $twig->render($template, ['salesChannel' => ['id' => '0191aaaaaaaa7000aaaaaaaaaaaaaaaa']]);
    }
}
