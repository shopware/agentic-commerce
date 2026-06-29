<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Embedded;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Bridge\EmbeddedPageRendererInterface;

/** @internal */
#[Package('checkout')]
final class ShopwareEmbeddedPageRenderer implements EmbeddedPageRendererInterface
{
    public function __construct(
        private readonly CartCapabilityInterface $cartCapability,
        private readonly CheckoutCapabilityInterface $checkoutCapability,
        private readonly Environment $twig,
    ) {
    }

    public function render(string $type, string $id, Request $request): ?Response
    {
        $context = $request->attributes->get('ucp_request_context');
        if (!$context instanceof RequestContext) {
            return null;
        }

        $data = match ($type) {
            'cart' => $this->cartCapability->getCart($id, $context)->toArray(),
            'checkout' => $this->checkoutCapability->getCheckout($id, $context)->toArray(),
            default => null,
        };

        if (!\is_array($data)) {
            return null;
        }

        // Cross-origin requests are filtered by EmbeddedResponseListener before
        // rendering; the fallback keeps same-origin iframes target-pinned.
        $targetOrigin = $request->headers->get('origin') ?: $request->getSchemeAndHttpHost();
        $state = [
            'channel' => 'ucp.embedded',
            'type' => $type,
            'id' => $id,
            'targetOrigin' => $targetOrigin,
            'data' => $data,
        ];

        return new Response($this->twig->render('@SwagAgenticCommerce/ucp/embedded/page.html.twig', [
            'type' => $type,
            'id' => $id,
            'title' => $this->title($type),
            'data' => $data,
            'stateJson' => json_encode($state, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_AMP | \JSON_HEX_QUOT),
        ]), Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Vary' => 'Origin',
        ]);
    }

    private function title(string $type): string
    {
        return 'checkout' === $type ? 'Checkout session' : 'Cart';
    }
}
