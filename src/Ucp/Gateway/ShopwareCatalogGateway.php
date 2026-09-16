<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Content\Product\SalesChannel\AbstractProductListRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[Package('inventory')]
final class ShopwareCatalogGateway
{
    /**
     * How many variants of one parent the batched query may scan before that parent is resolved
     * by a query of its own.
     */
    private const VARIANTS_SCANNED_PER_PARENT = 50;

    public function __construct(
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly ContextTokenGenerator $contextTokenGenerator,
        private readonly UcpConfigService $configService,
        private readonly AbstractProductSearchRoute $productSearchRoute,
        private readonly AbstractProductListRoute $productListRoute,
        private readonly AbstractProductDetailRoute $productDetailRoute,
        private readonly ShopwareDataMapper $mapper,
    ) {
    }

    /**
     * @return list<\Ucp\Sdk\Model\Catalog\Product>
     */
    public function search(string $query, int $limit, RequestContext $requestContext): array
    {
        $context = $this->contextResolver->resolve($this->contextTokenGenerator->generate(), $requestContext);
        $limit = $this->requestLimit($limit, $context->getSalesChannelId());
        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $this->withVariantOptions($criteria);

        // UCP's `query` is optional free text, so an empty one is a valid request that asks for
        // the catalog rather than for a match. Shopware's search route answers an empty term
        // with nothing, which reads to an agent as an empty shop; list instead.
        if ('' === trim($query)) {
            $criteria->addSorting(new FieldSorting('name'), new FieldSorting('id'));
            $this->listOneRowPerVariantGroup($criteria);
            $entities = $this->productListRoute->load($criteria, $context)->getProducts();
        } else {
            $entities = $this->productSearchRoute->load(new Request([
                'search' => $query,
                'limit' => $limit,
            ]), $context, $criteria)->getListingResult()->getEntities();
        }

        $products = [];

        foreach ($entities as $product) {
            if (!$product instanceof SalesChannelProductEntity) {
                continue;
            }

            $products[] = $this->mapper->toProduct($product, $context);
        }

        return \array_slice($products, 0, $limit);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<\Ucp\Sdk\Model\Catalog\Product>
     */
    public function lookup(array $ids, RequestContext $requestContext): array
    {
        $context = $this->contextResolver->resolve($this->contextTokenGenerator->generate(), $requestContext);
        $ids = \array_slice($ids, 0, $this->configService->getConfig($context->getSalesChannelId())->catalogResultLimit);
        if ([] === $ids) {
            return [];
        }

        $criteria = new Criteria($ids);
        $this->withVariantOptions($criteria);
        $response = $this->productListRoute->load($criteria, $context);
        $products = [];

        foreach ($response->getProducts() as $product) {
            $products[$product->getId()] = $product;
        }

        // A parent is not buyable, so answering with its id -- which is what asking for one used
        // to do, listed as its own variant -- sets the agent up for a cart that silently stays
        // empty. Only paid for when a parent actually came back.
        $parentIds = [];
        foreach ($products as $product) {
            if ($product->getChildCount() > 0) {
                $parentIds[] = $product->getId();
            }
        }

        $representatives = [];
        if ([] !== $parentIds) {
            $representatives = $this->representativeVariants($parentIds, $context);
            $replacementCriteria = new Criteria(array_values($representatives));
            $this->withVariantOptions($replacementCriteria);
            foreach ($this->productListRoute->load($replacementCriteria, $context)->getProducts() as $variant) {
                $products[$variant->getId()] = $variant;
            }
        }

        $orderedProducts = [];
        foreach ($ids as $id) {
            $loadId = $representatives[$id] ?? $id;
            if (isset($products[$loadId])) {
                // The requested id stays the lookup input, so the agent can still match the answer
                // to its question even when what it gets back is the representative variant.
                $orderedProducts[] = $this->mapper->toProduct($products[$loadId], $context, $id);
            }
        }

        return $orderedProducts;
    }

    public function getProduct(string $id, RequestContext $requestContext): \Ucp\Sdk\Model\Catalog\Product
    {
        $context = $this->contextResolver->resolve($this->contextTokenGenerator->generate(), $requestContext);

        return $this->getProductForContext($id, $context);
    }

    private function getProductForContext(
        string $id,
        \Shopware\Core\System\SalesChannel\SalesChannelContext $context,
    ): \Ucp\Sdk\Model\Catalog\Product {
        // ProductDetailRoute resolves a parent itself, through findBestVariant(), which orders by
        // availability and price with no tiebreaker -- so a product whose variants share a price
        // answered with a different variant on every call. Resolve it here instead, deterministically.
        $id = $this->representativeVariants([$id], $context)[$id] ?? $id;

        $criteria = new Criteria([$id]);
        $this->withVariantOptions($criteria);
        $response = $this->productDetailRoute->load($id, new Request(), $context, $criteria);

        return $this->mapper->toProduct($response->getProduct(), $context);
    }

    /**
     * The variant that stands in for each requested id that turns out to be a parent.
     *
     * One ordering, used by every path, so lookup, product and the cart pick the same variant and
     * keep picking it. `id` is the tiebreaker core is missing.
     *
     * The DAL cannot express "one row per parent", so the window is bounded per parent instead of
     * by total variant count; a parent that did not fit is resolved on its own below.
     *
     * @param list<string> $ids
     *
     * @return array<string, string> parent id => representative variant id
     */
    private function representativeVariants(array $ids, \Shopware\Core\System\SalesChannel\SalesChannelContext $context): array
    {
        if ([] === $ids) {
            return [];
        }

        $criteria = $this->representativeCriteria($ids);
        $criteria->setLimit(\count($ids) * self::VARIANTS_SCANNED_PER_PARENT);

        $representatives = [];
        foreach ($this->productListRoute->load($criteria, $context)->getProducts() as $variant) {
            $parentId = $variant->getParentId();
            if (null === $parentId || isset($representatives[$parentId])) {
                continue;
            }

            $representatives[$parentId] = $variant->getId();
        }

        foreach ($ids as $id) {
            if (isset($representatives[$id])) {
                continue;
            }

            $criteria = $this->representativeCriteria([$id]);
            $criteria->setLimit(1);
            $variant = $this->productListRoute->load($criteria, $context)->getProducts()->first();
            if (null !== $variant) {
                $representatives[$id] = $variant->getId();
            }
        }

        return $representatives;
    }

    /**
     * @param list<string> $ids
     */
    private function representativeCriteria(array $ids): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('parentId', $ids));
        $criteria->addSorting(
            // parentId first so one parent's variants are contiguous and a bounded window covers
            // whole parents; the three that follow are what decides which variant represents one.
            new FieldSorting('parentId'),
            new FieldSorting('available', FieldSorting::DESCENDING),
            new FieldSorting('price'),
            new FieldSorting('id'),
        );

        return $criteria;
    }

    /**
     * The listing semantics `ProductListRoute` does not have.
     *
     * `ProductSearchRoute` runs through `ProductListingLoader`, which groups by `displayGroup`
     * and drops the null ones; `ProductListRoute` is a plain DAL read and does neither. Browsing
     * therefore returned the parent of every variant product -- which cannot be bought, so a cart
     * built from it comes back empty -- plus one row per variant, all wearing the parent's name.
     *
     * `VariantListingUpdater` is what makes these two lines sufficient: a parent with children
     * always has `display_group = NULL`, and all children of one parent share its hash.
     */
    private function listOneRowPerVariantGroup(Criteria $criteria): void
    {
        $criteria->addGroupField(new FieldGrouping('displayGroup'));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('displayGroup', null)]));
    }

    /**
     * Without the options and their groups, `ProductSubscriber` builds an empty `variation` and
     * every variant of a product answers with the parent's name -- three rows called "physical",
     * with nothing to tell a buyer which is which.
     */
    private function withVariantOptions(Criteria $criteria): void
    {
        $criteria->addAssociation('options.group');
    }

    private function requestLimit(int $requestedLimit, string $salesChannelId): int
    {
        return min(max(1, $requestedLimit), $this->configService->getConfig($salesChannelId)->catalogResultLimit);
    }
}
