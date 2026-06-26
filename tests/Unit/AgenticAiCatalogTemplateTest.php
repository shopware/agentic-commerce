<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** @internal */
final class AgenticAiCatalogTemplateTest extends TestCase
{
    public function testFallbackCatalogRendersUcpProfileWithAbsoluteUrl(): void
    {
        $twig = new Environment($this->createLoader());
        $twig->addFunction(new TwigFunction('swag_agentic_commerce_ucp_active', static fn (): bool => true));

        $catalog = json_decode($twig->render('files/agentic/.well-known/ai-catalog.json.twig', [
            'salesChannel' => [
                'name' => 'Demo shop',
                'translated' => ['name' => 'Demo shop'],
            ],
            'salesChannelFileContext' => [
                'baseUrl' => 'https://shop.example.com',
                'publisher' => 'shop.example.com',
            ],
        ]), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('Demo shop', $catalog['host']['displayName']);
        static::assertSame('https://shop.example.com/.well-known/ucp', $catalog['entries'][0]['url']);
    }

    private function createLoader(): FilesystemLoader
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../src/AgenticFiles/Fallback/Resources/views');
        $loader->addPath(__DIR__.'/../../src/Resources/views', 'SwagAgenticCommerce');

        return $loader;
    }
}
