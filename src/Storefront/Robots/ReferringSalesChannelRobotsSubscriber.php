<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Storefront\Robots;

use Shopware\Storefront\Page\Robots\RobotsPageLoadedEvent;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirective;
use Shopware\Storefront\Page\Robots\Struct\RobotsDirectiveType;
use Shopware\Storefront\Page\Robots\Struct\RobotsUserAgentBlock;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds an Allow directive for the sales-channel tracking parameter to the storefront
 * robots.txt. The Google feed appends `referringSalesChannel` to every product link, and
 * Shopware's default `Disallow: /*?` would otherwise stop Googlebot from crawling those
 * URLs, which makes Google Merchant Center disapprove of the products. The clean URL still
 * wins in the search index through the page's rel=canonical and the feed's g:canonical_link.
 *
 * @internal
 */
final class ReferringSalesChannelRobotsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ShopwareVersionDetector $versionDetector)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [RobotsPageLoadedEvent::class => 'allowTrackingParameter'];
    }

    public function allowTrackingParameter(RobotsPageLoadedEvent $event): void
    {
        if (!$this->versionDetector->needsRobotsTrackingAllowPatch()) {
            return;
        }

        $page = $event->getPage();

        $directive = new RobotsDirective(
            RobotsDirectiveType::ALLOW,
            '/*'.SalesChannelTrackingListener::QUERY_PARAM.'='
        );

        $blocks = $page->getGlobalUserAgentBlocks();
        $blocks[] = new RobotsUserAgentBlock('*', [$directive]);
        $page->setGlobalUserAgentBlocks($blocks);
    }
}
