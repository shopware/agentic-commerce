<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Tax\TaxCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'swag-agentic-commerce:seed-smoke-catalog',
    description: 'Creates a minimal active storefront product for smoke tests.',
    hidden: true,
)]
/** @internal Smoke-only helper used by the portable integration runner. */
final class SeedSmokeCatalogCommand extends Command
{
    private const PRODUCT_NUMBER = 'SWAG-AGENTIC-COMMERCE-SMOKE';

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<TaxCollection>     $taxRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $taxRepository,
        private readonly string $appEnv,
        private readonly bool $smokeCatalogSeedEnabled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel-id', null, InputOption::VALUE_REQUIRED, 'The storefront sales channel id that should see the smoke product.');
        $this->addOption('product-name', null, InputOption::VALUE_OPTIONAL, 'The smoke product name.', 'Smoke Music Album');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->smokeCatalogSeedEnabled || 'prod' === $this->appEnv) {
            $output->writeln('<error>The smoke catalog seeder is disabled. Set SWAG_AGENTIC_COMMERCE_SMOKE_SEED=1 in a non-prod environment.</error>');

            return self::FAILURE;
        }

        $salesChannelId = (string) $input->getOption('sales-channel-id');

        if (!Uuid::isValid($salesChannelId)) {
            $output->writeln('<error>The --sales-channel-id option must be a valid Shopware id.</error>');

            return self::FAILURE;
        }

        $context = Context::createCLIContext();
        $productName = trim((string) $input->getOption('product-name'));
        $productName = '' !== $productName ? $productName : 'Smoke Music Album';

        $existingId = $this->productRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('productNumber', self::PRODUCT_NUMBER)),
            $context
        )->firstId();

        if (null !== $existingId) {
            $output->writeln($existingId);

            return self::SUCCESS;
        }

        $taxId = $this->taxRepository->searchIds((new Criteria())->setLimit(1), $context)->firstId();

        if (null === $taxId) {
            $output->writeln('<error>Unable to resolve a tax id for the smoke product.</error>');

            return self::FAILURE;
        }

        $productId = Uuid::randomHex();

        $this->productRepository->upsert([
            [
                'id' => $productId,
                'productNumber' => self::PRODUCT_NUMBER,
                'active' => true,
                'stock' => 100,
                'name' => $productName,
                'description' => 'Portable music-themed smoke test product for UCP catalog, cart, and checkout flows.',
                'taxId' => $taxId,
                'price' => [
                    [
                        'currencyId' => Defaults::CURRENCY,
                        'gross' => 19.99,
                        'net' => 16.8,
                        'linked' => false,
                    ],
                ],
                'visibilities' => [
                    [
                        'salesChannelId' => $salesChannelId,
                        'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
                    ],
                ],
            ],
        ], $context);

        $output->writeln($productId);

        return self::SUCCESS;
    }
}
