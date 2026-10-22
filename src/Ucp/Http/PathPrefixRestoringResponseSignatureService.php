<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Http;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Http\HttpResponse;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Service\ResponseSignatureServiceInterface;

/** @internal */
#[Package('framework')]
final class PathPrefixRestoringResponseSignatureService implements ResponseSignatureServiceInterface
{
    public function __construct(
        private readonly ResponseSignatureServiceInterface $inner,
        private readonly PathPrefixRestorer $pathPrefixRestorer,
    ) {
    }

    public function sign(
        HttpResponse $response,
        HttpRequest $request,
        ManagedSigningKey $key,
        ?int $created = null,
        ?int $expires = null,
    ): array {
        return $this->inner->sign(
            $response,
            new HttpRequest(
                $request->method,
                $this->pathPrefixRestorer->restore($request->absoluteUri),
                $request->headers,
                $request->query,
                $request->body,
            ),
            $key,
            $created,
            $expires,
        );
    }
}
