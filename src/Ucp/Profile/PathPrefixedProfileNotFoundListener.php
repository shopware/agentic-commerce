<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Profile;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/** @internal */
#[Package('framework')]
final class PathPrefixedProfileNotFoundListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly SalesChannelDomainResolver $domainResolver,
        private readonly UcpProfileLocator $profileLocator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || UcpProfileLocator::PROFILE_PATH !== $request->getPathInfo()) {
            return;
        }

        $requestDomain = $this->domainResolver->resolveByRequest($request);
        if (null === $requestDomain || null !== $this->profileLocator->profileUrl($requestDomain->baseUrl)) {
            return;
        }

        throw new NotFoundHttpException();
    }
}
