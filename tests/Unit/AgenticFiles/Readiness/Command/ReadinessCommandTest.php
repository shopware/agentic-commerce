<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\AgenticFiles\Readiness\Command\AbstractReadinessCommand;
use Swag\AgenticCommerce\AgenticFiles\Readiness\Command\ReadinessEnableCommand;
use Swag\AgenticCommerce\AgenticFiles\Readiness\Command\ReadinessStatusCommand;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures\BuildsReadinessService;
use Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures\InMemoryAgenticFilesCoreBridge;
use Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures\ReadinessShop;
use Swag\AgenticCommerce\Ucp\Command\SalesChannelResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(AbstractReadinessCommand::class)]
#[CoversClass(ReadinessEnableCommand::class)]
#[CoversClass(ReadinessStatusCommand::class)]
final class ReadinessCommandTest extends TestCase
{
    use BuildsReadinessService;

    private InMemoryAgenticFilesCoreBridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = new InMemoryAgenticFilesCoreBridge();
    }

    public function testTheCommandNamesAreTheStableInterface(): void
    {
        static::assertSame('swag-agentic-commerce:readiness:enable', $this->enableCommand()->getName());
        static::assertSame('swag-agentic-commerce:readiness:status', $this->statusCommand()->getName());
    }

    public function testAllStorefrontsEnablesTheFilesOnStorefrontAndHeadlessChannels(): void
    {
        $tester = new CommandTester($this->enableCommand());

        $tester->execute(['--all-storefronts' => true], ['interactive' => false]);

        $display = $tester->getDisplay();
        static::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        static::assertSame([ReadinessShop::STOREFRONT_ID, ReadinessShop::HEADLESS_ID], array_keys($this->bridge->rows));
        static::assertMatchesRegularExpression('/Storefront\s+'.ReadinessShop::STOREFRONT_ID.'\s+llms\.txt\s+created/', $display);
        static::assertMatchesRegularExpression('/Headless\s+'.ReadinessShop::HEADLESS_ID.'\s+AGENTS\.md\s+created/', $display);
        static::assertStringNotContainsString(ReadinessShop::FEED_ID, $display);
        static::assertStringContainsString('Created 4 file(s).', $display);
    }

    public function testTheSalesChannelOptionIsRepeatableAndTakesNamesOrIds(): void
    {
        $tester = new CommandTester($this->enableCommand());

        $tester->execute(['--sales-channel' => ['storefront', ReadinessShop::HEADLESS_ID]], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        static::assertSame([ReadinessShop::STOREFRONT_ID, ReadinessShop::HEADLESS_ID], array_keys($this->bridge->rows));
    }

    public function testASecondRunReportsNothingToChangeAndSucceeds(): void
    {
        $tester = new CommandTester($this->enableCommand());
        $tester->execute(['--all-storefronts' => true], ['interactive' => false]);

        $tester->execute(['--all-storefronts' => true], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString('Nothing to change.', $tester->getDisplay());
    }

    public function testAFileSwitchedOffByTheMerchantIsReportedAndStaysOff(): void
    {
        $this->bridge->rows[ReadinessShop::STOREFRONT_ID] = ['AGENTS.md' => false];
        $tester = new CommandTester($this->enableCommand());

        $tester->execute(['--sales-channel' => ['Storefront']], ['interactive' => false]);

        $display = $tester->getDisplay();
        static::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        static::assertFalse($this->bridge->rows[ReadinessShop::STOREFRONT_ID]['AGENTS.md']);
        static::assertMatchesRegularExpression('/AGENTS\.md\s+disabled by merchant, left off/', $display);
        static::assertStringContainsString('1 file(s) stay off because they were switched off for the sales channel.', $this->flatDisplay($tester));
    }

    public function testADryRunWritesNothing(): void
    {
        $tester = new CommandTester($this->enableCommand());

        $tester->execute(['--all-storefronts' => true, '--dry-run' => true], ['interactive' => false]);

        $display = $tester->getDisplay();
        static::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        static::assertSame([], $this->bridge->rows);
        static::assertMatchesRegularExpression('/llms\.txt\s+missing, would be created/', $display);
        static::assertStringContainsString('Dry run: 4 file(s) would be created. Nothing was written.', $this->flatDisplay($tester));
    }

    public function testItSucceedsWithAMessageWhenCoreDoesNotServeSalesChannelFiles(): void
    {
        $this->bridge->supportsCoreFiles = false;
        $tester = new CommandTester($this->enableCommand());

        $tester->execute(['--all-storefronts' => true], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString('needs Shopware 6.7.12 or newer. Nothing was changed.', $this->flatDisplay($tester));
        static::assertSame([], $this->bridge->rows);
    }

    public function testItSucceedsWithAMessageWhenNoSalesChannelIsEligible(): void
    {
        $tester = new CommandTester($this->enableCommand([
            ReadinessShop::FEED_ID => ['Feed', SalesChannelTypeClassification::ProductComparison],
        ]));

        $tester->execute(['--all-storefronts' => true], ['interactive' => false]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString('There is no Storefront or Headless sales channel. Nothing was changed.', $this->flatDisplay($tester));
    }

    public function testItRefusesToRunWithoutASelection(): void
    {
        $tester = new CommandTester($this->enableCommand());

        $tester->execute([], ['interactive' => false]);

        static::assertSame(Command::INVALID, $tester->getStatusCode());
        static::assertStringContainsString('--all-storefronts', $tester->getDisplay());
        static::assertSame([], $this->bridge->rows);
    }

    public function testItRefusesBothSelectionOptionsAtOnce(): void
    {
        $tester = new CommandTester($this->enableCommand());

        $tester->execute(['--all-storefronts' => true, '--sales-channel' => ['Storefront']], ['interactive' => false]);

        static::assertSame(Command::INVALID, $tester->getStatusCode());
        static::assertStringContainsString('Pass either --sales-channel or --all-storefronts, not both.', $this->flatDisplay($tester));
        static::assertSame([], $this->bridge->rows);
    }

    public function testItRefusesAnUnknownSalesChannel(): void
    {
        $tester = new CommandTester($this->enableCommand());

        $tester->execute(['--sales-channel' => ['Storefront', 'Nope']], ['interactive' => false]);

        static::assertSame(Command::INVALID, $tester->getStatusCode());
        static::assertStringContainsString('No sales channel matches "Nope".', $tester->getDisplay());
        static::assertSame([], $this->bridge->rows, 'One invalid value stops the whole run.');
    }

    public function testItRefusesAProductComparisonSalesChannel(): void
    {
        $tester = new CommandTester($this->enableCommand());

        $tester->execute(['--sales-channel' => ['Feed']], ['interactive' => false]);

        static::assertSame(Command::INVALID, $tester->getStatusCode());
        static::assertSame([], $this->bridge->rows);
    }

    public function testTheStatusListsEachFileAndWritesNothing(): void
    {
        $this->bridge->rows[ReadinessShop::STOREFRONT_ID] = ['llms.txt' => true, 'AGENTS.md' => false];
        $tester = new CommandTester($this->statusCommand());

        $tester->execute(['--all-storefronts' => true], ['interactive' => false]);

        $display = $tester->getDisplay();
        static::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        static::assertMatchesRegularExpression('/Storefront\s+'.ReadinessShop::STOREFRONT_ID.'\s+llms\.txt\s+enabled/', $display);
        static::assertMatchesRegularExpression('/Storefront\s+'.ReadinessShop::STOREFRONT_ID.'\s+AGENTS\.md\s+disabled by merchant/', $display);
        static::assertStringNotContainsString('left off', $display);
        static::assertMatchesRegularExpression('/Headless\s+'.ReadinessShop::HEADLESS_ID.'\s+llms\.txt\s+missing/', $display);
        static::assertStringContainsString('2 file(s) missing. Create them with swag-agentic-commerce:readiness:enable.', $this->flatDisplay($tester));
        static::assertSame([ReadinessShop::STOREFRONT_ID => ['llms.txt' => true, 'AGENTS.md' => false]], $this->bridge->rows);
    }

    public function testTheStatusRefusesToRunWithoutASelection(): void
    {
        $tester = new CommandTester($this->statusCommand());

        $tester->execute([], ['interactive' => false]);

        static::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    private function flatDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) preg_replace('/^ ! /m', '', $tester->getDisplay()));
    }

    /**
     * @param ?array<string, array{string, SalesChannelTypeClassification}> $salesChannels
     */
    private function enableCommand(?array $salesChannels = null): ReadinessEnableCommand
    {
        return new ReadinessEnableCommand($this->readinessService($this->bridge, $salesChannels), $this->resolver($salesChannels));
    }

    private function statusCommand(): ReadinessStatusCommand
    {
        return new ReadinessStatusCommand($this->readinessService($this->bridge), $this->resolver(null));
    }

    /**
     * @param ?array<string, array{string, SalesChannelTypeClassification}> $salesChannels
     */
    private function resolver(?array $salesChannels): SalesChannelResolver
    {
        return new SalesChannelResolver($this->salesChannelViewProvider($salesChannels));
    }
}
