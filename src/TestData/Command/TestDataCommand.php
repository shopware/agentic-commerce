<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\Catalogue\BuiltInCatalogue;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueArchive;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueProduct;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueSource;
use Swag\AgenticCommerce\TestData\Catalogue\ProductPicker;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\Seeder\PayPalSeeder;
use Swag\AgenticCommerce\TestData\Seeder\TestDataSeederInterface;
use Swag\AgenticCommerce\TestData\TestDataException;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\Ucp\Command\SalesChannelResolver;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(
    name: 'swag-agentic-commerce:test-data',
    description: 'Creates, or with --remove deletes, the Agentic Commerce test data set.',
)]
#[Package('framework')]
final class TestDataCommand extends Command
{
    private const REPORT_HEADERS = ['Data', 'Status', 'Detail'];

    /**
     * @param list<TestDataSeederInterface> $seeders in creation order; each may reference the ones before it
     */
    public function __construct(
        private readonly array $seeders,
        private readonly SalesChannelResolver $salesChannelResolver,
        private readonly UcpConfigService $ucpConfigService,
        private readonly string $appEnv,
        private readonly CatalogueSource $catalogueSource,
        private readonly ProductPicker $productPicker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sales channel (id or name) that sees the test data. Defaults to every channel with UCP enabled.');
        $this->addOption('remove', null, InputOption::VALUE_NONE, 'Removes the test data set instead of creating it.');
        $this->addOption('catalogue', null, InputOption::VALUE_NONE, 'Uses the product catalogue with images without asking; downloads it when it is not cached.');
        $this->addOption('offline', null, InputOption::VALUE_NONE, 'Uses the built-in products without images and never downloads.');
        $this->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Repeats an earlier choice of catalogue products; the report prints the seed of every run.');
        $this->setHelp(\sprintf(
            'Every created entity has a deterministic id, which is what --remove deletes. Products and rules are also named with the prefix <info>%s</info>, products numbered with <info>%s</info>, entities with custom fields carry the marker <info>%s</info>, and PayPal assignments are recorded in <info>%s</info>.'."\n\n".'Asked interactively, the command offers the product catalogue with images and English and German texts, a signed CC0 release fetched from <info>%s</info> and cached in the Shopware cache directory. Without interaction it stays offline unless --catalogue is given.',
            trim(TestDataIds::NAME_PREFIX),
            TestDataIds::PRODUCT_NUMBER_PREFIX,
            TestDataIds::MARKER,
            PayPalSeeder::ASSIGNMENTS_CONFIG_KEY,
            $this->catalogueSource->baseUrl(),
        ));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->appEnv) {
            $io->error('The test data command does not run with APP_ENV=prod.');

            return self::FAILURE;
        }

        $context = self::createContext();

        if (true === $input->getOption('remove')) {
            return $this->remove($io, $context);
        }

        /** @var list<string> $requestedChannels */
        $requestedChannels = $input->getOption('sales-channel');
        $salesChannelIds = $this->resolveSalesChannelIds($input, $io, $requestedChannels);
        if (null === $salesChannelIds) {
            return self::FAILURE;
        }

        if (true === $input->getOption('catalogue') && true === $input->getOption('offline')) {
            $io->error('Pass either --catalogue or --offline, not both.');

            return self::FAILURE;
        }

        $seed = $input->getOption('seed');
        if (null !== $seed && (!\is_string($seed) || !ctype_digit($seed))) {
            $io->error('--seed must be a whole number.');

            return self::FAILURE;
        }

        try {
            $pickedProducts = $this->pickProducts($input, $io, null === $seed ? random_int(1, 999_999) : (int) $seed);
        } catch (TestDataException $exception) {
            $io->error([$exception->getMessage(), 'Run with --offline to use the built-in products.']);

            return self::FAILURE;
        }

        return $this->create($io, $salesChannelIds, $pickedProducts, $context);
    }

    private function pickProducts(InputInterface $input, SymfonyStyle $io, int $seed): PickedProducts
    {
        $isRequested = true === $input->getOption('catalogue');
        if (true === $input->getOption('offline') || (!$isRequested && !$input->isInteractive())) {
            return BuiltInCatalogue::pickedProducts();
        }

        try {
            $release = $this->catalogueSource->fetchLatestRelease();
            $isCached = $this->catalogueSource->isArchiveCached($release);
            $question = $isCached
                ? \sprintf('Use the product catalogue %s with images (cached)?', $release->version)
                : \sprintf('Use the product catalogue %s with images? It downloads %.0F MB once.', $release->version, $release->archiveBytes / 1e6);
        } catch (TestDataException $exception) {
            $release = $this->catalogueSource->newestCachedRelease();
            if (null === $release) {
                if ($isRequested) {
                    throw $exception;
                }
                $io->warning([$exception->getMessage(), 'Using the built-in products.']);

                return BuiltInCatalogue::pickedProducts();
            }
            $io->warning([$exception->getMessage(), \sprintf('Falling back to the cached catalogue %s.', $release->version)]);
            $question = \sprintf('Use the cached product catalogue %s with images?', $release->version);
        }

        if (!$isRequested && !$io->confirm($question, true)) {
            return BuiltInCatalogue::pickedProducts();
        }

        return $this->productPicker->pick(CatalogueArchive::open($this->catalogueSource->downloadArchive($release)), $seed);
    }

    /**
     * @param list<string> $salesChannelIds
     */
    private function create(SymfonyStyle $io, array $salesChannelIds, PickedProducts $pickedProducts, Context $context): int
    {
        $rows = [];
        foreach ($this->seeders as $seeder) {
            $unavailableReason = $seeder->unavailableReason();
            if (null !== $unavailableReason) {
                $rows[] = [$seeder->label(), TestDataStatus::Skipped->value, $unavailableReason];
                continue;
            }

            if ($seeder->exists($context)) {
                $rows[] = [$seeder->label(), TestDataStatus::Present->value, 'Run with --remove first to recreate it.'];
                continue;
            }

            try {
                $rows[] = [$seeder->label(), TestDataStatus::Created->value, implode("\n", $seeder->create($salesChannelIds, $pickedProducts, $context))];
            } catch (\Throwable $exception) {
                $rows[] = [$seeder->label(), TestDataStatus::Failed->value, $exception->getMessage()];
                $io->table(self::REPORT_HEADERS, $rows);
                $io->error('Creating the test data failed. Run the command with --remove to delete the partial data, then run it again.');

                return self::FAILURE;
            }
        }

        $io->writeln(\sprintf('Sales channels: %s', implode(', ', $salesChannelIds)));
        $io->writeln(null === $pickedProducts->catalogue->version
            ? 'Products: built-in, without images'
            : \sprintf('Products: catalogue %s, seed %d (repeat the choice with --seed %2$d)', $pickedProducts->catalogue->version, $pickedProducts->seed));
        if (null !== $pickedProducts->catalogue->version) {
            $io->listing(array_map(static fn (string $role, CatalogueProduct $product): string => $role.': '.$product->id, array_keys($pickedProducts->productByRole), $pickedProducts->productByRole));
        }
        $io->table(self::REPORT_HEADERS, $rows);
        $io->success('Test data set is ready. Remove it with --remove.');

        return self::SUCCESS;
    }

    private function remove(SymfonyStyle $io, Context $context): int
    {
        $rows = [];
        foreach (array_reverse($this->seeders) as $seeder) {
            $rows[] = [$seeder->label(), ($seeder->remove($context) ? TestDataStatus::Removed : TestDataStatus::Absent)->value, ''];
        }

        $io->table(self::REPORT_HEADERS, $rows);
        $io->success('Test data set removed.');

        return self::SUCCESS;
    }

    /**
     * @param list<string> $requestedChannels
     *
     * @return ?list<string> null when a requested channel does not exist or no channel qualifies
     */
    private function resolveSalesChannelIds(InputInterface $input, SymfonyStyle $io, array $requestedChannels): ?array
    {
        if ([] === $requestedChannels) {
            $ucpConfigBySalesChannelId = $this->ucpConfigService->getConfigs(array_map(static fn (array $channel): string => $channel['id'], $this->salesChannelResolver->all()));
            $salesChannelIds = array_keys(array_filter($ucpConfigBySalesChannelId, static fn (UcpConfig $config): bool => $config->active));
            if ([] === $salesChannelIds) {
                $io->error('No sales channel has UCP enabled. Pass --sales-channel, or enable UCP first (see ucp:channels).');

                return null;
            }

            return array_map('strval', $salesChannelIds);
        }

        $salesChannelIds = [];
        foreach ($requestedChannels as $requestedChannel) {
            $salesChannelId = $this->salesChannelResolver->resolve($input, $io, $requestedChannel, false);
            if (!\is_string($salesChannelId)) {
                return null;
            }

            $salesChannelIds[] = $salesChannelId;
        }

        return array_values(array_unique($salesChannelIds));
    }

    private static function createContext(): Context
    {
        // createCLIContext() arrived in Shopware 6.6.
        // @phpstan-ignore function.alreadyNarrowedType, shopware.cliContext, staticMethod.notFound
        return method_exists(Context::class, 'createCLIContext') ? Context::createCLIContext() : Context::createDefaultContext();
    }
}
