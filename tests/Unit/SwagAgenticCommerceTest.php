<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * The archive ships no dependencies, so Shopware has to install them.
 *
 * `executeComposerCommands()` is what makes it run `composer require shopware/agentic-commerce`
 * against the project on a zip install, which resolves the extracted archive through the
 * `custom/plugins/*` path repository and pulls the pinned SDK from Packagist. 1.3.0 returned false
 * here and vendored the SDK into the plugin instead, which left a second Composer ClassLoader in
 * every shop that installed it. Both halves are asserted: either one alone leaves the plugin
 * without an SDK at runtime.
 *
 * @internal
 */
#[CoversClass(SwagAgenticCommerce::class)]
final class SwagAgenticCommerceTest extends TestCase
{
    #[Test]
    public function testItLetsShopwareInstallComposerDependencies(): void
    {
        $plugin = new SwagAgenticCommerce(true, \dirname(__DIR__, 2));

        self::assertTrue($plugin->executeComposerCommands());
    }

    #[Test]
    public function testTheManifestRequiresTheSdkComposerHasToResolve(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 2).'/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR
        );

        self::assertIsArray($manifest);
        $require = $manifest['require'] ?? null;
        self::assertIsArray($require);

        foreach (['ucp-php-sdk/core', 'ucp-php-sdk/symfony-bundle'] as $package) {
            self::assertArrayHasKey($package, $require, \sprintf('%s has to stay required.', $package));
            self::assertMatchesRegularExpression(
                '/^\d+\.\d+\.\d+$/',
                (string) $require[$package],
                'An exact version is the only constraint that cannot hand a shop an SDK we never tested.',
            );
        }
    }
}
