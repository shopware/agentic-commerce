<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Http;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;

/** @internal */
#[Package('framework')]
final class PathPrefixRestoringRequestContextFactory implements HttpRequestContextFactoryInterface
{
    public function __construct(
        private readonly HttpRequestContextFactoryInterface $inner,
        private readonly PathPrefixRestorer $pathPrefixRestorer,
    ) {
    }

    public function create(HttpRequest $request): RequestContext
    {
        return $this->inner->create(new HttpRequest(
            $request->method,
            $this->pathPrefixRestorer->restore($request->absoluteUri),
            $request->headers,
            $request->query,
            $request->body,
        ));
    }
}
