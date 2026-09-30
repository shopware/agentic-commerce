<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\ShopLanguages;
use Swag\AgenticCommerce\TestData\ShopLanguagesLoader;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * Removal finds the trees by their name prefix, so it also cleans up below a sales channel whose navigation root
 * changed since the test data was created.
 *
 * @internal
 */
#[Package('framework')]
final class CategorySeeder implements TestDataSeederInterface
{
    use DeletesExistingIds;

    private const TREE_NAME_ENGLISH = 'Test products';
    private const TREE_NAME_GERMAN = 'Testprodukte';

    /**
     * @param EntityRepository<CategoryCollection> $categoryRepository
     */
    public function __construct(
        private readonly EntityRepository $categoryRepository,
        private readonly CategoryTreeIds $categoryTreeIds,
        private readonly ShopLanguagesLoader $shopLanguagesLoader,
    ) {
    }

    public function label(): string
    {
        return 'Category tree';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function exists(Context $context): bool
    {
        return [] !== $this->existingTreeIds($context);
    }

    public function create(array $salesChannelIds, PickedProducts $pickedProducts, Context $context): array
    {
        $usedKeys = [];
        foreach ($pickedProducts->productByRole as $product) {
            $usedKeys += array_fill_keys($product->categoryKeys, true);
        }
        $categoryNameByKey = array_intersect_key($pickedProducts->catalogue->categoryNameByKey, $usedKeys);

        $shopLanguages = $this->shopLanguagesLoader->load($context);
        $trees = [];
        foreach ($this->categoryTreeIds->navigationRootIds($salesChannelIds, $context) as $rootId) {
            $children = [];
            $previousId = null;
            foreach ($categoryNameByKey as $key => $name) {
                $children[] = [...$this->categoryPayload(CategoryTreeIds::categoryId($rootId, $key), $name, $shopLanguages), 'afterCategoryId' => $previousId];
                $previousId = CategoryTreeIds::categoryId($rootId, $key);
            }
            $trees[] = [
                ...$this->categoryPayload(CategoryTreeIds::treeId($rootId), TestDataIds::prefixedTranslatedName(new TranslatedText(self::TREE_NAME_ENGLISH, self::TREE_NAME_GERMAN)), $shopLanguages),
                'parentId' => $rootId,
                'afterCategoryId' => $this->lastCategoryIdBelow($rootId, $context),
                'children' => $children,
            ];
        }
        if ([] !== $trees) {
            $this->categoryRepository->upsert($trees, $context);
        }

        return [\sprintf('%s with %s, in %d navigation tree(s)', TestDataIds::prefixedName(self::TREE_NAME_ENGLISH), implode(', ', array_map(static fn (TranslatedText $name): string => $name->english, $categoryNameByKey)), \count($trees))];
    }

    public function remove(Context $context): bool
    {
        $treeIds = $this->existingTreeIds($context);
        if ([] === $treeIds) {
            return false;
        }

        $childIds = self::stringIds($this->categoryRepository->searchIds((new Criteria())->addFilter(new EqualsAnyFilter('parentId', $treeIds)), $context));
        $this->deleteExisting($this->categoryRepository, $childIds, $context);

        return $this->deleteExisting($this->categoryRepository, $treeIds, $context);
    }

    // Without an afterCategoryId the tree would compete with the root's first category for the first position.
    private function lastCategoryIdBelow(string $rootId, Context $context): ?string
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('parentId', $rootId));
        $afterIdByChildId = [];
        foreach ($this->categoryRepository->search($criteria, $context)->getEntities() as $child) {
            $afterIdByChildId[$child->getId()] = $child->getAfterCategoryId();
        }
        unset($afterIdByChildId[CategoryTreeIds::treeId($rootId)]);
        $lastChildIds = array_diff(array_keys($afterIdByChildId), array_filter($afterIdByChildId));

        return [] === $lastChildIds ? null : (string) reset($lastChildIds);
    }

    /**
     * @return list<string>
     */
    private function existingTreeIds(Context $context): array
    {
        $criteria = (new Criteria())->addFilter(new PrefixFilter('name', TestDataIds::NAME_PREFIX));

        return self::stringIds($this->categoryRepository->searchIds($criteria, $context));
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryPayload(string $id, TranslatedText $name, ShopLanguages $shopLanguages): array
    {
        return [
            'id' => $id,
            ...$shopLanguages->translatedFields(['name' => $name]),
            'active' => true,
            'visible' => true,
            'type' => CategoryDefinition::TYPE_PAGE,
            'displayNestedProducts' => true,
            'productAssignmentType' => CategoryDefinition::PRODUCT_ASSIGNMENT_TYPE_PRODUCT,
            'customFields' => TestDataIds::markerCustomField(),
        ];
    }
}
