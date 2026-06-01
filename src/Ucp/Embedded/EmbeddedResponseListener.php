<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Embedded;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[Package('checkout')]
final readonly class EmbeddedResponseListener
{
    public function __construct(
        private UcpConfigService $configService,
        private SalesChannelDomainResolver $domainResolver,
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

        if (!\is_string($origin) || '' === $origin || [] === $config->embeddedAllowedOrigins) {
            return;
        }

        if (!\in_array($origin, $config->embeddedAllowedOrigins, true)) {
            $event->setResponse(new JsonResponse([
                'ucp' => [
                    'status' => 'error',
                ],
                'messages' => [[
                    'type' => 'error',
                    'content' => 'Embedded origin is not allowlisted for this sales channel.',
                ]],
            ], Response::HTTP_FORBIDDEN));
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

        if (\is_string($origin) && \in_array($origin, $config->embeddedAllowedOrigins, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
    }

    private function isEmbeddedRequest(string $path): bool
    {
        return str_starts_with($path, '/ucp/embedded/');
    }
}
