<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Swag\AgenticCommerce\Ucp\Http\PathPrefixRestorer;
use Swag\AgenticCommerce\Ucp\Http\PathPrefixRestoringResponseSignatureService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Http\HttpResponse;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Service\ResponseSignatureServiceInterface;

/**
 * @internal
 */
#[CoversClass(PathPrefixRestoringResponseSignatureService::class)]
class PathPrefixRestoringResponseSignatureServiceTest extends TestCase
{
    public function testItBindsTheResponseToTheUriTheAgentCalled(): void
    {
        $cartRequestWithStrippedPrefix = Request::create('https://shop.example/ucp/v1/carts/c1');
        $cartRequestWithStrippedPrefix->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/de/ucp/v1/carts/c1');
        $requestStack = new RequestStack();
        $requestStack->push($cartRequestWithStrippedPrefix);

        $cartResponse = new HttpResponse(200, ['content-type' => 'application/json'], '{}');
        $signingKey = new ManagedSigningKey('shop-key', 'public-key-pem', 'private-key-pem');

        $innerService = $this->createMock(ResponseSignatureServiceInterface::class);
        $innerService->expects(static::once())->method('sign')->with(
            $cartResponse,
            static::equalTo(new HttpRequest('GET', 'https://shop.example/de/ucp/v1/carts/c1', ['ucp-agent' => 'profile="https://agent.example/profile.json"'])),
            $signingKey,
            1700000000,
            1700000300,
        )->willReturn(['signature' => 'sig1=:c2lnbmF0dXJl:']);

        $signatureHeaders = (new PathPrefixRestoringResponseSignatureService($innerService, new PathPrefixRestorer($requestStack)))->sign(
            $cartResponse,
            new HttpRequest('GET', 'https://shop.example/ucp/v1/carts/c1', ['ucp-agent' => 'profile="https://agent.example/profile.json"']),
            $signingKey,
            1700000000,
            1700000300,
        );

        static::assertSame(['signature' => 'sig1=:c2lnbmF0dXJl:'], $signatureHeaders);
    }
}
