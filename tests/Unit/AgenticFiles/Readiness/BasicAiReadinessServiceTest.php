<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessException;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessReport;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessService;
use Swag\AgenticCommerce\AgenticFiles\Readiness\ReadinessFileState;
use Swag\AgenticCommerce\AgenticFiles\Readiness\SalesChannelReadiness;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures\BuildsReadinessService;
use Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures\InMemoryAgenticFilesCoreBridge;
use Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures\ReadinessShop;

/**
 * @internal
 */
#[CoversClass(BasicAiReadinessService::class)]
#[CoversClass(BasicAiReadinessReport::class)]
#[CoversClass(SalesChannelReadiness::class)]
final class BasicAiReadinessServiceTest extends TestCase
{
    use BuildsReadinessService;

    private InMemoryAgenticFilesCoreBridge $bridge;

    private BasicAiReadinessService $service;

    protected function setUp(): void
    {
        $this->bridge = new InMemoryAgenticFilesCoreBridge();
        $this->service = $this->readinessService($this->bridge);
    }

    public function testItCreatesLlmsTxtAndAgentsMdOnStorefrontAndHeadlessChannelsOnly(): void
    {
        $report = $this->service->enable(null, false, Context::createDefaultContext());

        static::assertSame([
            ReadinessShop::STOREFRONT_ID => ['llms.txt' => true, 'AGENTS.md' => true],
            ReadinessShop::HEADLESS_ID => ['llms.txt' => true, 'AGENTS.md' => true],
        ], $this->bridge->rows);
        static::assertSame([ReadinessShop::STOREFRONT_ID, ReadinessShop::HEADLESS_ID], $this->reportedIds($report));
        static::assertSame(4, $report->countFiles(ReadinessFileState::Created));
        static::assertSame([], $this->bridge->ucpCalls, 'The UCP activation path must not be used.');
    }

    public function testItLeavesAFileTheMerchantSwitchedOffSwitchedOff(): void
    {
        $this->bridge->rows[ReadinessShop::STOREFRONT_ID] = ['llms.txt' => false];

        $report = $this->service->enable([ReadinessShop::STOREFRONT_ID], false, Context::createDefaultContext());

        static::assertSame(['llms.txt' => false, 'AGENTS.md' => true], $this->bridge->rows[ReadinessShop::STOREFRONT_ID]);
        static::assertSame([
            'llms.txt' => ReadinessFileState::DisabledByMerchant,
            'AGENTS.md' => ReadinessFileState::Created,
        ], $report->salesChannels[0]->files);
    }

    public function testItCountsALowerCaseAgentsMdRowAsPresent(): void
    {
        $this->bridge->rows[ReadinessShop::STOREFRONT_ID] = ['agents.md' => true];

        $report = $this->service->enable([ReadinessShop::STOREFRONT_ID], false, Context::createDefaultContext());

        static::assertSame(['agents.md' => true, 'llms.txt' => true], $this->bridge->rows[ReadinessShop::STOREFRONT_ID]);
        static::assertSame(ReadinessFileState::Enabled, $report->salesChannels[0]->files['AGENTS.md']);
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->service->enable(null, false, Context::createDefaultContext());
        $rowsAfterFirstRun = $this->bridge->rows;

        $report = $this->service->enable(null, false, Context::createDefaultContext());

        static::assertSame($rowsAfterFirstRun, $this->bridge->rows);
        static::assertSame(0, $report->countFiles(ReadinessFileState::Created));
        static::assertSame(4, $report->countFiles(ReadinessFileState::Enabled));
    }

    public function testARowCreatedConcurrentlyIsReportedAsStoredInsteadOfAsCreated(): void
    {
        $bridge = $this->createMock(AgenticFilesCoreBridgeInterface::class);
        $bridge->method('supportsCoreFiles')->willReturn(true);
        $bridge->method('fileStates')->willReturnOnConsecutiveCalls(
            [],
            [ReadinessShop::STOREFRONT_ID => ['llms.txt' => true, 'AGENTS.md' => false]],
        );
        $bridge->expects(static::once())->method('insertMissingFiles')
            ->with(ReadinessShop::STOREFRONT_ID, ['llms.txt', 'AGENTS.md'])
            ->willReturn(['llms.txt']);
        $service = new BasicAiReadinessService($bridge, $this->salesChannelViewProvider([
            ReadinessShop::STOREFRONT_ID => ['Storefront', SalesChannelTypeClassification::Storefront],
        ]));

        $report = $service->enable(null, false, Context::createDefaultContext());

        static::assertSame([
            'llms.txt' => ReadinessFileState::Created,
            'AGENTS.md' => ReadinessFileState::DisabledByMerchant,
        ], $report->salesChannels[0]->files);
    }

    public function testADryRunReportsTheMissingFilesAndWritesNothing(): void
    {
        $report = $this->service->enable(null, true, Context::createDefaultContext());

        static::assertSame([], $this->bridge->rows);
        static::assertTrue($report->dryRun);
        static::assertSame(4, $report->countFiles(ReadinessFileState::Missing));
    }

    public function testTheStatusWritesNothing(): void
    {
        $this->bridge->rows[ReadinessShop::HEADLESS_ID] = ['llms.txt' => true, 'AGENTS.md' => false];

        $report = $this->service->status(null, Context::createDefaultContext());

        static::assertSame([ReadinessShop::HEADLESS_ID => ['llms.txt' => true, 'AGENTS.md' => false]], $this->bridge->rows);
        static::assertFalse($report->dryRun);
        static::assertSame(['llms.txt' => ReadinessFileState::Missing, 'AGENTS.md' => ReadinessFileState::Missing], $report->salesChannels[0]->files);
        static::assertSame(['llms.txt' => ReadinessFileState::Enabled, 'AGENTS.md' => ReadinessFileState::DisabledByMerchant], $report->salesChannels[1]->files);
    }

    public function testItChangesNothingWhereCoreDoesNotServeSalesChannelFiles(): void
    {
        $this->bridge->supportsCoreFiles = false;

        $report = $this->service->enable(null, false, Context::createDefaultContext());

        static::assertSame([], $this->bridge->rows);
        static::assertFalse($report->coreSupported);
        static::assertSame([], $report->salesChannels);
        static::assertSame(BasicAiReadinessReport::CORE_SUPPORT_MISSING_MESSAGE, $report->jsonSerialize()['message']);
    }

    public function testItRejectsASalesChannelThatIsNotEligible(): void
    {
        try {
            $this->service->enable([ReadinessShop::STOREFRONT_ID, ReadinessShop::FEED_ID], false, Context::createDefaultContext());
            static::fail('A product comparison sales channel must be rejected.');
        } catch (BasicAiReadinessException $exception) {
            static::assertSame(BasicAiReadinessException::SALES_CHANNEL_NOT_ELIGIBLE, $exception->getErrorCode());
            static::assertSame(['salesChannelIds' => ReadinessShop::FEED_ID], $exception->getParameters());
        }

        static::assertSame([], $this->bridge->rows, 'Nothing is written when part of the selection is invalid.');
    }

    public function testItReportsAnEmptySelectionWhenNoChannelIsEligible(): void
    {
        $service = $this->readinessService($this->bridge, [
            ReadinessShop::FEED_ID => ['Feed', SalesChannelTypeClassification::ProductComparison],
        ]);

        $report = $service->enable(null, false, Context::createDefaultContext());

        static::assertTrue($report->coreSupported);
        static::assertSame([], $report->salesChannels);
    }

    public function testTheReportSerializesTheStructureTheApiReturns(): void
    {
        $this->bridge->rows[ReadinessShop::STOREFRONT_ID] = ['llms.txt' => false];

        $payload = json_decode((string) json_encode($this->service->enable([ReadinessShop::STOREFRONT_ID], false, Context::createDefaultContext())), true);

        static::assertSame([
            'coreSupported' => true,
            'message' => null,
            'dryRun' => false,
            'salesChannels' => [[
                'id' => ReadinessShop::STOREFRONT_ID,
                'name' => 'Storefront',
                'files' => [
                    ['fileName' => 'llms.txt', 'state' => 'disabled_by_merchant'],
                    ['fileName' => 'AGENTS.md', 'state' => 'created'],
                ],
            ]],
        ], $payload);
    }

    /**
     * @return list<string>
     */
    private function reportedIds(BasicAiReadinessReport $report): array
    {
        return array_map(static fn (SalesChannelReadiness $salesChannel): string => $salesChannel->id, $report->salesChannels);
    }
}
