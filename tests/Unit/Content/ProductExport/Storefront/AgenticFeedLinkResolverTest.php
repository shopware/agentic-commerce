<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Storefront;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductExport\ProductExportCollection;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Content\ProductExport\Storefront\AgenticFeedLinkResolver;

/** @internal */
#[CoversClass(AgenticFeedLinkResolver::class)]
final class AgenticFeedLinkResolverTest extends TestCase
{
    public function testReturnsFeedPathForGeneratedGoogleExport(): void
    {
        $resolver = new AgenticFeedLinkResolver(
            $this->repositoryReturning($this->export('SWAGKEY123', 'feed.xml')),
        );

        static::assertSame(
            '/store-api/product-export/SWAGKEY123/feed.xml',
            $resolver->resolveFeedPath(Uuid::randomHex()),
        );
    }

    public function testReturnsNullWhenNoExportMatches(): void
    {
        $resolver = new AgenticFeedLinkResolver($this->repositoryReturning(null));

        static::assertNull($resolver->resolveFeedPath(Uuid::randomHex()));
    }

    public function testReturnsNullForEmptySalesChannelId(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(static::never())->method('search');

        $resolver = new AgenticFeedLinkResolver($repository);

        static::assertNull($resolver->resolveFeedPath(''));
    }

    private function export(string $accessKey, string $fileName): ProductExportEntity
    {
        $export = new ProductExportEntity();
        $export->setUniqueIdentifier(Uuid::randomHex());
        $export->setAccessKey($accessKey);
        $export->setFileName($fileName);

        return $export;
    }

    /**
     * @return EntityRepository<ProductExportCollection>
     */
    private function repositoryReturning(?ProductExportEntity $export): EntityRepository
    {
        $collection = new ProductExportCollection($export !== null ? [$export] : []);
        $result = new EntitySearchResult(
            'product_export',
            $collection->count(),
            $collection,
            null,
            new Criteria(),
            \Shopware\Core\Framework\Context::createDefaultContext(),
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }
}
