<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Swag\AgenticCommerce\Ucp\Http\PathPrefixRestorer;
use Swag\AgenticCommerce\Ucp\Http\PathPrefixRestoringRequestContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;

/**
 * @internal
 */
#[CoversClass(PathPrefixRestoringRequestContextFactory::class)]
#[CoversClass(PathPrefixRestorer::class)]
class PathPrefixRestoringRequestContextFactoryTest extends TestCase
{
    public function testItHandsTheSdkTheUriTheAgentSigned(): void
    {
        $cartRequestWithStrippedPrefix = Request::create('https://shop.example/ucp/v1/carts?expand=items', Request::METHOD_POST);
        $cartRequestWithStrippedPrefix->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/de/ucp/v1/carts?expand=items');

        $innerFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $innerFactory->expects(static::once())->method('create')->with(static::equalTo(new HttpRequest(
            'POST',
            'https://shop.example/de/ucp/v1/carts?expand=items',
            ['ucp-agent' => 'profile="https://agent.example/profile.json"'],
            ['expand' => 'items'],
            '{"line_items":[]}',
        )))->willReturn(new RequestContext('shop.example'));

        $this->factory($innerFactory, $cartRequestWithStrippedPrefix)->create(new HttpRequest(
            'POST',
            'https://shop.example/ucp/v1/carts?expand=items',
            ['ucp-agent' => 'profile="https://agent.example/profile.json"'],
            ['expand' => 'items'],
            '{"line_items":[]}',
        ));
    }

    public function testItKeepsTheUriOfARequestNotOnTheStack(): void
    {
        $unrelatedRequest = Request::create('https://shop.example/checkout');
        $unrelatedRequest->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/de/checkout');

        $innerFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $innerFactory->expects(static::once())->method('create')->with(static::equalTo(new HttpRequest('POST', 'https://example.invalid')))
            ->willReturn(new RequestContext('example.invalid'));

        $this->factory($innerFactory, $unrelatedRequest)->create(new HttpRequest('POST', 'https://example.invalid'));
    }

    private function factory(HttpRequestContextFactoryInterface $innerFactory, Request $mainRequest): PathPrefixRestoringRequestContextFactory
    {
        $requestStack = new RequestStack();
        $requestStack->push($mainRequest);

        return new PathPrefixRestoringRequestContextFactory($innerFactory, new PathPrefixRestorer($requestStack));
    }
}
