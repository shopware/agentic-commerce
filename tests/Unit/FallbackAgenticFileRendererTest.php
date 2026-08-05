<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\AgenticFiles\Fallback\FallbackAgenticFileRenderer;

/** @internal */
final class FallbackAgenticFileRendererTest extends TestCase
{
    public function testItBuildsContextFromCurrentDomain(): void
    {
        $salesChannelId = Uuid::randomHex();
        $currentDomainId = Uuid::randomHex();
        $salesChannel = $this->salesChannel([
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://first.example.com/'),
            $this->domain($currentDomainId, $salesChannelId, 'https://SHOP.Example.COM/en/'),
        ]);

        static::assertSame([
            'baseUrl' => 'https://SHOP.Example.COM/en',
            'publisher' => 'shop.example.com',
        ], $this->buildSalesChannelFileContext($salesChannel, $this->context($currentDomainId)));
    }

    public function testItFallsBackToFirstDomain(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannel = $this->salesChannel([
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://fallback.example.com/'),
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://other.example.com/'),
        ]);

        static::assertSame([
            'baseUrl' => 'https://fallback.example.com',
            'publisher' => 'fallback.example.com',
        ], $this->buildSalesChannelFileContext($salesChannel, $this->context(Uuid::randomHex())));
    }

    public function testItReturnsNullContextWithoutDomains(): void
    {
        static::assertSame([
            'baseUrl' => null,
            'publisher' => null,
        ], $this->buildSalesChannelFileContext(new SalesChannelEntity(), $this->context(null)));
    }

    /**
     * @return array{baseUrl: string|null, publisher: string|null}
     */
    private function buildSalesChannelFileContext(SalesChannelEntity $salesChannel, SalesChannelContext $context): array
    {
        $renderer = (new \ReflectionClass(FallbackAgenticFileRenderer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(FallbackAgenticFileRenderer::class, 'buildSalesChannelFileContext');
        $result = $method->invoke($renderer, $salesChannel, $context);

        static::assertIsArray($result);

        $baseUrl = $result['baseUrl'] ?? null;
        $publisher = $result['publisher'] ?? null;

        if (null !== $baseUrl && !\is_string($baseUrl)) {
            static::fail('Expected baseUrl to be null or string.');
        }

        if (null !== $publisher && !\is_string($publisher)) {
            static::fail('Expected publisher to be null or string.');
        }

        return [
            'baseUrl' => $baseUrl,
            'publisher' => $publisher,
        ];
    }

    private function context(?string $domainId): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getDomainId')->willReturn($domainId);

        return $context;
    }

    /**
     * @param list<SalesChannelDomainEntity> $domains
     */
    private function salesChannel(array $domains): SalesChannelEntity
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setDomains(new SalesChannelDomainCollection($domains));

        return $salesChannel;
    }

    private function domain(string $id, string $salesChannelId, string $url): SalesChannelDomainEntity
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId($id);
        $domain->setSalesChannelId($salesChannelId);
        $domain->setLanguageId(Uuid::randomHex());
        $domain->setUrl($url);

        return $domain;
    }
}
