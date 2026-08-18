<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\AgenticFiles\Fallback\FallbackAgenticFileRenderer;
use Swag\AgenticCommerce\AgenticFiles\SalesChannelBaseUrlResolver;

/**
 * @internal
 */
#[CoversClass(FallbackAgenticFileRenderer::class)]
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
        ], $this->buildSalesChannelFileContext($this->context($salesChannel, $currentDomainId)));
    }

    public function testItReturnsNullContextWithoutDomains(): void
    {
        static::assertSame([
            'baseUrl' => null,
            'publisher' => null,
        ], $this->buildSalesChannelFileContext($this->context(new SalesChannelEntity(), null)));
    }

    /**
     * @return array{baseUrl: string|null, publisher: string|null}
     */
    private function buildSalesChannelFileContext(SalesChannelContext $context): array
    {
        $renderer = (new \ReflectionClass(FallbackAgenticFileRenderer::class))->newInstanceWithoutConstructor();
        // The tested contexts already carry their domains, so the resolver never queries; a bare
        // repository mock is enough to satisfy the resolver dependency.
        (new \ReflectionProperty(FallbackAgenticFileRenderer::class, 'baseUrlResolver'))
            ->setValue($renderer, new SalesChannelBaseUrlResolver($this->createMock(EntityRepository::class)));

        $method = new \ReflectionMethod(FallbackAgenticFileRenderer::class, 'buildSalesChannelFileContext');
        $result = $method->invoke($renderer, $context);

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

    private function context(SalesChannelEntity $salesChannel, ?string $domainId): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);
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
