<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileSyncSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CoreSalesChannelFileSyncSubscriberTest extends TestCase
{
    public function testItSyncsOnAgenticFileRequest(): void
    {
        $bridge = new CountingAgenticFilesCoreBridge();
        $subscriber = new CoreSalesChannelFileSyncSubscriber($bridge);

        $subscriber->syncOnAgenticFileRequest($this->createRequestEvent('/llms.txt'));
        $subscriber->syncOnAgenticFileRequest($this->createRequestEvent('/agents.md'));

        static::assertSame(2, $bridge->syncs);
    }

    public function testItIgnoresOtherRequests(): void
    {
        $bridge = new CountingAgenticFilesCoreBridge();
        $subscriber = new CoreSalesChannelFileSyncSubscriber($bridge);

        $subscriber->syncOnAgenticFileRequest($this->createRequestEvent('/robots.txt'));

        static::assertSame(0, $bridge->syncs);
    }

    private function createRequestEvent(string $path): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}

final class CountingAgenticFilesCoreBridge implements AgenticFilesCoreBridgeInterface
{
    public int $syncs = 0;

    public function enableForSalesChannel(string $salesChannelId): void
    {
    }

    public function syncActiveUcpSalesChannels(): void
    {
        ++$this->syncs;
    }
}
