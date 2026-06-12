<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Test\Api;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\Ucp\Test\WebhookCaptureStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @internal
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID], 'auth_required' => false])]
#[Package('framework')]
final class TestWebhookController
{
    public function __construct(
        private readonly WebhookCaptureStore $captureStore,
        private readonly string $appEnv,
        private readonly bool $testCaptureEnabled,
    ) {
    }

    #[Route(path: '/_action/swag-agentic-commerce/test/webhooks', name: 'frontend.action.swag_agentic_commerce.test.webhooks.capture', methods: ['POST'])]
    public function capture(Request $request): JsonResponse
    {
        $this->assertAvailable();

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $payload = null;
        if ('' !== $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            if (\is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $this->captureStore->save([
            'capturedAt' => gmdate(\DATE_ATOM),
            'headers' => $headers,
            'body' => $request->getContent(),
            'payload' => $payload,
        ]);

        return new JsonResponse(['success' => true], Response::HTTP_CREATED);
    }

    #[Route(path: '/_action/swag-agentic-commerce/test/webhooks', name: 'frontend.action.swag_agentic_commerce.test.webhooks.show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $this->assertAvailable();

        $capture = $this->captureStore->load();
        if (null === $capture) {
            return new JsonResponse(['data' => null], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $capture]);
    }

    #[Route(path: '/_action/swag-agentic-commerce/test/webhooks', name: 'frontend.action.swag_agentic_commerce.test.webhooks.clear', methods: ['DELETE'])]
    public function clear(): Response
    {
        $this->assertAvailable();
        $this->captureStore->clear();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function assertAvailable(): void
    {
        if ($this->testCaptureEnabled && 'prod' !== $this->appEnv) {
            return;
        }

        throw new NotFoundHttpException();
    }
}
