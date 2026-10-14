<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Swag\AgenticCommerce\AgenticFiles\Readiness\Api\BasicAiReadinessController;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessException;
use Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures\BuildsReadinessService;
use Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures\InMemoryAgenticFilesCoreBridge;
use Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures\ReadinessShop;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[CoversClass(BasicAiReadinessController::class)]
#[CoversClass(BasicAiReadinessException::class)]
final class BasicAiReadinessControllerTest extends TestCase
{
    use BuildsReadinessService;

    private InMemoryAgenticFilesCoreBridge $bridge;

    private BasicAiReadinessController $controller;

    protected function setUp(): void
    {
        $this->bridge = new InMemoryAgenticFilesCoreBridge();
        $this->controller = new BasicAiReadinessController($this->readinessService($this->bridge));
    }

    public function testEnableWithoutABodyCoversEveryEligibleSalesChannelAndReturnsTheServiceReport(): void
    {
        $response = $this->controller->enable(new Request(), Context::createDefaultContext());

        $serviceReport = $this->readinessService(new InMemoryAgenticFilesCoreBridge())->enable(null, false, Context::createDefaultContext());

        static::assertSame([ReadinessShop::STOREFRONT_ID, ReadinessShop::HEADLESS_ID], array_keys($this->bridge->rows));
        static::assertSame(json_decode((string) json_encode($serviceReport), true), $this->data($response));
    }

    public function testASecondEnableReturnsTheSameStructureWithEverythingEnabled(): void
    {
        $this->controller->enable(new Request(), Context::createDefaultContext());

        $data = $this->data($this->controller->enable(new Request(), Context::createDefaultContext()));

        static::assertSame(
            ['enabled', 'enabled', 'enabled', 'enabled'],
            array_merge(...array_map(static fn (array $salesChannel): array => array_column($salesChannel['files'], 'state'), $data['salesChannels'])),
        );
    }

    public function testEnableTakesASelectionAndADryRunFromTheBody(): void
    {
        $data = $this->data($this->controller->enable($this->jsonRequest(['salesChannelIds' => [ReadinessShop::HEADLESS_ID], 'dryRun' => true]), Context::createDefaultContext()));

        static::assertSame([], $this->bridge->rows);
        static::assertTrue($data['dryRun']);
        static::assertSame([ReadinessShop::HEADLESS_ID], array_column($data['salesChannels'], 'id'));
        static::assertSame(['missing', 'missing'], array_column($data['salesChannels'][0]['files'], 'state'));
    }

    public function testEnableReportsMissingCoreSupportWithoutFailing(): void
    {
        $this->bridge->supportsCoreFiles = false;

        $response = $this->controller->enable(new Request(), Context::createDefaultContext());

        static::assertSame(200, $response->getStatusCode());
        $data = $this->data($response);
        static::assertFalse($data['coreSupported']);
        static::assertIsString($data['message']);
        static::assertSame([], $data['salesChannels']);
    }

    public function testEnableRejectsASalesChannelThatIsNotEligible(): void
    {
        $this->expectExceptionObject(BasicAiReadinessException::salesChannelsNotEligible([ReadinessShop::FEED_ID]));

        $this->controller->enable($this->jsonRequest(['salesChannelIds' => [ReadinessShop::FEED_ID]]), Context::createDefaultContext());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'a body that is not JSON' => ['salesChannelIds='];
        yield 'a dry run that is not a boolean' => ['{"dryRun":"yes"}'];
        yield 'an empty selection' => ['{"salesChannelIds":[]}'];
        yield 'a selection that is not a list' => ['{"salesChannelIds":{"a":"b"}}'];
        yield 'a selection with a non-string id' => ['{"salesChannelIds":[1]}'];
    }

    #[DataProvider('invalidBodies')]
    public function testEnableRejectsAnInvalidBodyAndWritesNothing(string $body): void
    {
        try {
            $this->controller->enable(new Request(content: $body), Context::createDefaultContext());
            static::fail('The body must be rejected.');
        } catch (BasicAiReadinessException $exception) {
            static::assertSame(400, $exception->getStatusCode());
            static::assertSame(BasicAiReadinessException::INVALID_PAYLOAD, $exception->getErrorCode());
        }

        static::assertSame([], $this->bridge->rows);
    }

    public function testStatusTakesAnOptionalSelectionFromTheQuery(): void
    {
        $this->bridge->rows[ReadinessShop::STOREFRONT_ID] = ['llms.txt' => false];

        $all = $this->data($this->controller->status(new Request(), Context::createDefaultContext()));
        $selected = $this->data($this->controller->status(new Request(['salesChannelIds' => [ReadinessShop::STOREFRONT_ID]]), Context::createDefaultContext()));

        static::assertSame([ReadinessShop::STOREFRONT_ID, ReadinessShop::HEADLESS_ID], array_column($all['salesChannels'], 'id'));
        static::assertSame([ReadinessShop::STOREFRONT_ID], array_column($selected['salesChannels'], 'id'));
        static::assertSame(['disabled_by_merchant', 'missing'], array_column($selected['salesChannels'][0]['files'], 'state'));
        static::assertSame([ReadinessShop::STOREFRONT_ID => ['llms.txt' => false]], $this->bridge->rows);
    }

    public function testStatusRejectsASelectionThatIsNotAList(): void
    {
        $this->expectException(BasicAiReadinessException::class);

        $this->controller->status(new Request(['salesChannelIds' => ReadinessShop::STOREFRONT_ID]), Context::createDefaultContext());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(array $payload): Request
    {
        return new Request(content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(JsonResponse $response): array
    {
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertIsArray($payload);
        static::assertIsArray($payload['data']);

        return $payload['data'];
    }
}
