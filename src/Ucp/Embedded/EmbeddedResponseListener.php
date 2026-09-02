<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Embedded;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/** @internal */
#[Package('checkout')]
final class EmbeddedResponseListener
{
    public function __construct(
        private readonly UcpConfigService $configService,
        private readonly SalesChannelDomainResolver $domainResolver,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isEmbeddedRequest($request->getPathInfo())) {
            return;
        }

        $config = $this->configService->getConfig($this->domainResolver->resolveByAbsoluteUri($request->getUri())?->salesChannelId);
        $origin = $request->headers->get('origin');

        if ([] === $config->embeddedAllowedOrigins) {
            $event->setResponse($this->forbidden('Embedded origins are not configured for this sales channel.'));

            return;
        }

        // A present-but-non-allowlisted Origin is rejected. An absent Origin is not
        // a denial signal: browsers omit the header on iframe and top-level GET
        // navigations, which is exactly how the embedded surface is loaded. Framing
        // is enforced by the frame-ancestors CSP set in onKernelResponse(), cross-origin
        // reads by the Access-Control-Allow-Origin grant, and the payload itself by
        // possession of the cart/checkout token in the URL. Denying origin-less
        // requests here would 403 every real browser load of this feature.
        if (\is_string($origin) && '' !== $origin && !\in_array($origin, $config->embeddedAllowedOrigins, true)) {
            $event->setResponse($this->forbidden('Embedded origin is not allowlisted for this sales channel.'));

            return;
        }

        if (Request::METHOD_OPTIONS === $request->getMethod()) {
            $event->setResponse($this->preflight($origin, $config));
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isEmbeddedRequest($request->getPathInfo())) {
            return;
        }

        $config = $this->configService->getConfig($this->domainResolver->resolveByAbsoluteUri($request->getUri())?->salesChannelId);
        $frameAncestors = [] !== $config->embeddedFrameAncestors ? $config->embeddedFrameAncestors : ["'self'"];
        $response = $event->getResponse();
        $origin = $request->headers->get('origin');

        $response->headers->set('Content-Security-Policy', 'frame-ancestors '.implode(' ', $frameAncestors));
        $response->headers->remove('X-Frame-Options');
        $vary = array_filter(array_map('trim', explode(',', $response->headers->get('Vary', ''))));
        $vary[] = 'Origin';
        $response->headers->set('Vary', implode(', ', array_values(array_unique($vary))));

        // The embedded URL carries the cart/checkout token and the body carries buyer
        // PII, so keep the page out of shared caches, search indexes, and Referer
        // headers on outbound navigations (e.g. the continue-checkout CTA).
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        if (\is_string($origin) && \in_array($origin, $config->embeddedAllowedOrigins, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
    }

    private function isEmbeddedRequest(string $path): bool
    {
        return str_starts_with($path, '/ucp/embedded/');
    }

    private function forbidden(string $message): JsonResponse
    {
        return new JsonResponse([
            'ucp' => [
                'status' => 'error',
            ],
            'messages' => [[
                'type' => 'error',
                'content' => $message,
            ]],
        ], Response::HTTP_FORBIDDEN);
    }

    private function preflight(?string $origin, UcpConfig $config): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $frameAncestors = [] !== $config->embeddedFrameAncestors ? $config->embeddedFrameAncestors : ["'self'"];

        // Origin-less OPTIONS requests (non-browser agents) get no CORS grant;
        // the allow-origin header is only meaningful for an allowlisted origin.
        if (\is_string($origin) && '' !== $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
        $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept');
        $response->headers->set('Content-Security-Policy', 'frame-ancestors '.implode(' ', $frameAncestors));
        $response->headers->remove('X-Frame-Options');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
