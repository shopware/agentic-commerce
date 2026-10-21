<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Swag\AgenticCommerce\Ucp\Profile\PathPrefixedProfileNotFoundListener;
use Swag\AgenticCommerce\Ucp\Profile\UcpProfileLocator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @internal
 */
#[CoversClass(PathPrefixedProfileNotFoundListener::class)]
final class PathPrefixedProfileNotFoundListenerTest extends TestCase
{
    public function testTheProfileBelowAPathPrefixIsNotFound(): void
    {
        $profileRequestWithStrippedPrefix = Request::create('https://shop.example/.well-known/ucp');
        $profileRequestWithStrippedPrefix->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/de/.well-known/ucp');

        $this->expectException(NotFoundHttpException::class);

        $this->listener()->onRequest($this->requestEvent($profileRequestWithStrippedPrefix));
    }

    public function testTheProfileAtTheOriginRootIsServed(): void
    {
        $rootProfileRequest = Request::create('https://shop.example/.well-known/ucp');
        $rootProfileRequest->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/.well-known/ucp');

        $this->listener()->onRequest($this->requestEvent($rootProfileRequest));

        $this->addToAssertionCount(1);
    }

    public function testOtherPathsBelowAPathPrefixAreServed(): void
    {
        $cartRequestWithStrippedPrefix = Request::create('https://shop.example/ucp/v1/carts');
        $cartRequestWithStrippedPrefix->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/de/ucp/v1/carts');

        $this->listener()->onRequest($this->requestEvent($cartRequestWithStrippedPrefix));

        $this->addToAssertionCount(1);
    }

    private function listener(): PathPrefixedProfileNotFoundListener
    {
        /** @var StaticEntityRepository<SalesChannelDomainCollection> $domainRepository */
        $domainRepository = new StaticEntityRepository([new SalesChannelDomainCollection([
            $this->domain('domain-root', 'https://shop.example'),
            $this->domain('domain-de', 'https://shop.example/de'),
        ])]);

        return new PathPrefixedProfileNotFoundListener(new SalesChannelDomainResolver($domainRepository), new UcpProfileLocator());
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function domain(string $id, string $url): SalesChannelDomainEntity
    {
        $registeredDomain = new SalesChannelDomainEntity();
        $registeredDomain->setId($id);
        $registeredDomain->setSalesChannelId('sales-channel-'.$id);
        $registeredDomain->setLanguageId('language-id');
        $registeredDomain->setCurrencyId('currency-id');
        $registeredDomain->setUrl($url);

        return $registeredDomain;
    }
}
