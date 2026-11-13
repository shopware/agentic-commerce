<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\HttpFoundation\Response;

/**
 * A platform whose profile is hosted outside the shop, under each platform policy.
 *
 * @internal
 */
final class UcpPlatformPolicyTest extends TestCase
{
    use UcpFlowTestBehaviour;

    private const UNLISTED_PROFILE = 'https://platform.example/.well-known/ucp';

    #[Test]
    public function testAnUnlistedPlatformIsRefusedWhenOnlyListedPlatformsAreAccepted(): void
    {
        $this->configureUcpRuntime();
        $this->savePlatformPolicy('listed');

        $response = $this->catalogSearchAs(self::UNLISTED_PROFILE);

        // UCP names 403 for this refusal. The SDK answers 401 until
        // https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/219 is fixed.
        self::assertContains(
            $response->getStatusCode(),
            [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN],
            (string) $response->getContent(),
        );
    }

    #[Test]
    public function testAnUnlistedPlatformIsAcceptedWhenAllPlatformsAreAccepted(): void
    {
        $this->configureUcpRuntime();
        $this->savePlatformPolicy('all');

        $response = $this->catalogSearchAs(self::UNLISTED_PROFILE);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function testAListedPlatformIsAcceptedWhenOnlyListedPlatformsAreAccepted(): void
    {
        $this->configureUcpRuntime();
        static::getContainer()->get(UcpConfigService::class)->saveConfig([
            'platformPolicy' => 'listed',
            'platformAllowlist' => ['platform.example'],
        ], $this->ucpSalesChannelId);

        $response = $this->catalogSearchAs(self::UNLISTED_PROFILE);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    private function savePlatformPolicy(string $platformPolicy): void
    {
        static::getContainer()->get(UcpConfigService::class)->saveConfig(
            ['platformPolicy' => $platformPolicy],
            $this->ucpSalesChannelId,
        );
    }

    private function catalogSearchAs(string $profileUri): Response
    {
        $this->browser->getCookieJar()->clear();
        $this->browser->request('POST', $this->ucpDomain.'/ucp/v1/catalog/search', [], [], [
            'HTTP_UCP_AGENT' => \sprintf('platform; profile="%s"', $profileUri),
            'HTTP_IDEMPOTENCY_KEY' => 'it-'.Uuid::randomHex(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['query' => 'Kernel', 'limit' => 1], \JSON_THROW_ON_ERROR));

        return $this->browser->getResponse();
    }
}
