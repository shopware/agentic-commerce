<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\ApiCatalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\AgenticFiles\ApiCatalog\ApiCatalogLinksetBuilder;
use Swag\AgenticCommerce\Ucp\Profile\UcpProfileLocator;

/**
 * @internal
 */
#[CoversClass(ApiCatalogLinksetBuilder::class)]
final class ApiCatalogLinksetBuilderTest extends TestCase
{
    public function testItLinksUcpProfileAndStoreApiRelativeToTheBaseUrl(): void
    {
        $result = $this->builder()->build('https://shop.example.com');

        static::assertSame([
            'linkset' => [
                [
                    'anchor' => 'https://shop.example.com/.well-known/api-catalog',
                    'service-meta' => [
                        ['href' => 'https://shop.example.com/.well-known/ucp', 'type' => 'application/json'],
                    ],
                    'item' => [
                        ['href' => 'https://shop.example.com/store-api', 'type' => 'application/json'],
                    ],
                ],
            ],
        ], $result);
    }

    public function testItLinksNoUcpProfileForABaseUrlWithAPath(): void
    {
        $result = $this->builder()->build('https://shop.example.com/en');

        static::assertSame([
            'linkset' => [
                [
                    'anchor' => 'https://shop.example.com/en/.well-known/api-catalog',
                    'item' => [
                        ['href' => 'https://shop.example.com/en/store-api', 'type' => 'application/json'],
                    ],
                ],
            ],
        ], $result);
    }

    public function testItEmitsRootRelativeReferencesForAnEmptyBaseUrl(): void
    {
        $result = $this->builder()->build('');

        static::assertSame([
            'linkset' => [
                [
                    'anchor' => '/.well-known/api-catalog',
                    'service-meta' => [
                        ['href' => '/.well-known/ucp', 'type' => 'application/json'],
                    ],
                    'item' => [
                        ['href' => '/store-api', 'type' => 'application/json'],
                    ],
                ],
            ],
        ], $result);
    }

    private function builder(): ApiCatalogLinksetBuilder
    {
        return new ApiCatalogLinksetBuilder(new UcpProfileLocator());
    }
}
