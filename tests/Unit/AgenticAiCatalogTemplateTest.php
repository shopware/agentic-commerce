<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Profile\UcpProfileLocator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** @internal */
final class AgenticAiCatalogTemplateTest extends TestCase
{
    public function testFallbackCatalogRendersUcpProfileWithAbsoluteUrl(): void
    {
        $catalog = $this->renderCatalog('https://shop.example.com');

        static::assertSame('Demo shop', $catalog['host']['displayName']);
        static::assertSame('https://shop.example.com/.well-known/ucp', $catalog['entries'][0]['url']);
    }

    public function testFallbackCatalogListsNoUcpProfileForADomainWithAPath(): void
    {
        $catalog = $this->renderCatalog('https://shop.example.com/de');

        static::assertSame([], $catalog['entries']);
    }

    /**
     * @return array<string, mixed>
     */
    private function renderCatalog(string $baseUrl): array
    {
        $twig = new Environment($this->createLoader());
        $twig->addFunction(new TwigFunction('swag_agentic_commerce_ucp_active', static fn (): bool => true));
        $twig->addFunction(new TwigFunction('swag_agentic_commerce_ucp_profile_url', (new UcpProfileLocator())->profileUrl(...)));

        /** @var array<string, mixed> $catalog */
        $catalog = json_decode($twig->render('files/agentic/.well-known/ai-catalog.json.twig', [
            'salesChannel' => [
                'name' => 'Demo shop',
                'translated' => ['name' => 'Demo shop'],
            ],
            'salesChannelFileContext' => [
                'baseUrl' => $baseUrl,
                'publisher' => 'shop.example.com',
            ],
        ]), true, 512, \JSON_THROW_ON_ERROR);

        return $catalog;
    }

    private function createLoader(): FilesystemLoader
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../src/AgenticFiles/Fallback/Resources/views');
        $loader->addPath(__DIR__.'/../../src/Resources/views', 'SwagAgenticCommerce');

        return $loader;
    }
}
