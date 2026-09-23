<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Routing\RouteScopeListener;
use Shopware\Core\Framework\Routing\RouteScopeRegistry;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\Ucp\Profile\UcpProfileRouteScopeWhitelist;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Ucp\Sdk\Service\ProfileBuilderInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\Controller\ProfileController;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/** @internal */
#[CoversClass(UcpProfileRouteScopeWhitelist::class)]
final class UcpProfileRouteScopeWhitelistTest extends TestCase
{
    #[Test]
    public function itAllowsTheRootProfileBeforeStorefrontScopeValidation(): void
    {
        $requestStack = new RequestStack();
        $mainRequest = Request::create('https://shop.example/.well-known/ucp');
        $requestStack->push($mainRequest);

        $whitelist = new UcpProfileRouteScopeWhitelist();
        $listener = new RouteScopeListener(
            new RouteScopeRegistry([new StorefrontRouteScope()]),
            $requestStack,
            [$whitelist],
        );

        $listener->checkScope($this->controllerEvent());

        static::assertTrue($whitelist->applies(ProfileController::class));
        static::assertFalse($whitelist->applies(self::class));
    }

    #[Test]
    public function itWouldRejectTheProfileWithoutTheWhitelist(): void
    {
        $this->expectException(RoutingException::class);

        $requestStack = new RequestStack();
        $mainRequest = Request::create('https://shop.example/.well-known/ucp');
        $mainRequest->attributes->set('_route', 'ucp_sdk_symfony_profile__invoke');
        $requestStack->push($mainRequest);

        $listener = new RouteScopeListener(
            new RouteScopeRegistry([new StorefrontRouteScope()]),
            $requestStack,
            [],
        );

        $listener->checkScope($this->controllerEvent());
    }

    private function controllerEvent(): ControllerEvent
    {
        $request = Request::create('https://shop.example/.well-known/ucp');
        $request->attributes->set('_route', 'ucp_sdk_symfony_profile__invoke');
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, [StorefrontRouteScope::ID]);

        return new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            \Closure::fromCallable($this->profileController()),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function profileController(): ProfileController
    {
        return new ProfileController(
            $this->createStub(ProfileBuilderInterface::class),
            new UcpSdkConfiguration(
                '2026-08-25',
                null,
                [],
                'strict',
                [],
                true,
                86400,
                1048576,
                300,
                86400,
                300,
                300,
                [],
                true,
                'default',
                'ES256',
                '+30 days',
                '+30 days',
                1048576,
                5,
                false,
                'sqlite:///:memory:',
            ),
            $this->createStub(RuntimeConfigurationResolverInterface::class),
        );
    }
}
