<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the UCP catalog capability (search / lookup / product) through the booted kernel against a
 * seeded storefront product, replacing the equivalent deployed-stack smoke assertions with readable
 * PHP. See {@see UcpFlowTestBehaviour} for the request-context setup.
 *
 * @internal
 */
final class UcpCatalogFlowTest extends TestCase
{
    use UcpFlowTestBehaviour;

    #[Test]
    public function testCatalogSearchLookupAndProductResolveASeededProduct(): void
    {
        $this->configureUcpRuntime();
        $productId = $this->seedStorefrontProduct('Kernel Test Album');

        $search = $this->ucpRequest('POST', '/ucp/v1/catalog/search', ['query' => 'Kernel', 'limit' => 3]);
        self::assertSame(Response::HTTP_OK, $search->getStatusCode());
        $searchProducts = $this->decode($search)['products'] ?? [];
        self::assertNotEmpty($searchProducts, 'Expected catalog.search to return the seeded product.');
        self::assertContains('Kernel Test Album', array_column($searchProducts, 'title'));

        $lookup = $this->ucpRequest('POST', '/ucp/v1/catalog/lookup', ['ids' => [$productId]]);
        self::assertSame(Response::HTTP_OK, $lookup->getStatusCode());
        $lookupProducts = $this->decode($lookup)['products'] ?? [];
        self::assertCount(1, $lookupProducts, 'Expected catalog.lookup to resolve exactly one product.');
        self::assertSame($productId, $lookupProducts[0]['id']);

        $product = $this->ucpRequest('GET', '/ucp/v1/catalog/product/'.$productId);
        self::assertSame(Response::HTTP_OK, $product->getStatusCode());
        $productBody = $this->decode($product);
        $resolved = $productBody['product'] ?? $productBody;
        self::assertSame('Kernel Test Album', $resolved['title']);
    }
}
