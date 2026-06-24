<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Storefront\Robots;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Storefront\Page\Robots\RobotsPage;
use Shopware\Storefront\Page\Robots\RobotsPageLoadedEvent;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirectiveType;
use Shopware\Storefront\Page\Robots\Struct\RobotsUserAgentBlock;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingListener;
use Swag\AgenticCommerce\Storefront\Robots\ReferringSalesChannelRobotsSubscriber;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[CoversClass(ReferringSalesChannelRobotsSubscriber::class)]
class ReferringSalesChannelRobotsSubscriberTest extends TestCase
{
    protected function setUp(): void
    {
        // The RobotsPage / RobotsPageLoadedEvent classes only exist from 6.7; on 6.5/6.6
        // there is nothing to construct and no robots.txt to extend.
        if (!class_exists(RobotsPageLoadedEvent::class)) {
            static::markTestSkipped('robots.txt subsystem requires Shopware >= 6.7');
        }
    }

    public function testItSubscribesToTheRobotsPageLoadedEvent(): void
    {
        static::assertSame(
            [RobotsPageLoadedEvent::class => 'allowTrackingParameter'],
            ReferringSalesChannelRobotsSubscriber::getSubscribedEvents()
        );
    }

    public function testItAddsAnAllowDirectiveOnVersionsWhereCoreLacksIt(): void
    {
        $page = $this->renderPageForVersion('6.7.12.0');

        $blocks = $page->getGlobalUserAgentBlocks();
        static::assertCount(1, $blocks);
        static::assertSame('*', $blocks[0]->userAgent);
        static::assertCount(1, $blocks[0]->directives);

        $directive = $blocks[0]->directives[0];
        static::assertSame(RobotsDirectiveType::ALLOW, $directive->type);
        static::assertSame('/*'.SalesChannelTrackingListener::QUERY_PARAM.'=', $directive->value);
        static::assertSame('/*referringSalesChannel=', $directive->value);
    }

    public function testItStaysOutOfTheWayOnceCoreShipsTheAllow(): void
    {
        $page = $this->renderPageForVersion('6.7.13.0');

        static::assertSame([], $page->getGlobalUserAgentBlocks());
    }

    public function testItDoesNotDiscardExistingBlocks(): void
    {
        $existing = new RobotsUserAgentBlock('Googlebot', []);
        $page = new RobotsPage();
        $page->setGlobalUserAgentBlocks([$existing]);
        $event = new RobotsPageLoadedEvent($page, Context::createDefaultContext(), new Request());

        (new ReferringSalesChannelRobotsSubscriber(new ShopwareVersionDetector(versionOverride: '6.7.12.0')))
            ->allowTrackingParameter($event);

        $blocks = $page->getGlobalUserAgentBlocks();
        static::assertCount(2, $blocks);
        static::assertSame($existing, $blocks[0]);
    }

    private function renderPageForVersion(string $version): RobotsPage
    {
        $page = new RobotsPage();
        $page->setGlobalUserAgentBlocks([]);
        $event = new RobotsPageLoadedEvent($page, Context::createDefaultContext(), new Request());

        (new ReferringSalesChannelRobotsSubscriber(new ShopwareVersionDetector(versionOverride: $version)))
            ->allowTrackingParameter($event);

        return $page;
    }
}
