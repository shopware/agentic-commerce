<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Embedded;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Bridge\EmbeddedPageRendererInterface;

#[Package('checkout')]
final readonly class ShopwareEmbeddedPageRenderer implements EmbeddedPageRendererInterface
{
    public function __construct(
        private CartCapabilityInterface $cartCapability,
        private CheckoutCapabilityInterface $checkoutCapability,
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

        return new Response($this->html($type, $id, $data, $request), Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Vary' => 'Origin',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function html(string $type, string $id, array $data, Request $request): string
    {
        $targetOrigin = $request->headers->get('origin') ?: $request->getSchemeAndHttpHost();
        $state = [
            'channel' => 'ucp.embedded',
            'type' => $type,
            'id' => $id,
            'targetOrigin' => $targetOrigin,
            'data' => $data,
        ];

        return \sprintf(
            <<<'HTML'
                <!doctype html>
                <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>%s</title>
                    <style>
                        :root { color-scheme: light; --ink: #17202a; --muted: #667085; --line: #d0d5dd; --surface: #fff; --accent: #0b5fff; }
                        * { box-sizing: border-box; }
                        body { margin: 0; background: #f7f9fc; color: var(--ink); font: 14px/1.45 ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
                        main { max-width: 760px; margin: 0 auto; padding: 20px; }
                        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 10px 30px rgba(16, 24, 40, .08); overflow: hidden; }
                        header { padding: 20px; border-bottom: 1px solid var(--line); display: flex; gap: 12px; justify-content: space-between; align-items: start; }
                        h1 { margin: 0; font-size: 20px; letter-spacing: -.02em; }
                        .meta, .empty { color: var(--muted); }
                        .content { padding: 20px; display: grid; gap: 18px; }
                        table { width: 100%%; border-collapse: collapse; }
                        th, td { padding: 10px 0; border-bottom: 1px solid #eef2f6; text-align: left; vertical-align: top; }
                        th:last-child, td:last-child { text-align: right; }
                        .totals { margin-left: auto; width: min(320px, 100%%); }
                        .total { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid #eef2f6; }
                        .total:last-child { font-weight: 700; border-bottom: 0; }
                        .status { display: inline-flex; padding: 5px 10px; border-radius: 999px; background: #e8f0ff; color: #053ca6; font-weight: 600; }
                        .cta { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 0 14px; border-radius: 10px; background: var(--accent); color: #fff; text-decoration: none; font-weight: 700; }
                    </style>
                </head>
                <body>
                <main>
                    <section class="card" aria-label="UCP embedded %s">
                        <header>
                            <div>
                                <h1>%s</h1>
                                <div class="meta">ID %s</div>
                            </div>
                            %s
                        </header>
                        <div class="content">
                            %s
                            %s
                            %s
                        </div>
                    </section>
                </main>
                <script>
                (() => {
                    const state = %s;
                    const targetOrigin = state.targetOrigin || window.location.origin;
                    function emit(type, detail = {}) {
                        window.parent.postMessage({
                            channel: state.channel,
                            type,
                            surface: state.type,
                            id: state.id,
                            payload: state.data,
                            detail
                        }, targetOrigin);
                    }
                    window.addEventListener('message', (event) => {
                        if (event.origin !== targetOrigin && targetOrigin !== window.location.origin) {
                            return;
                        }
                        const data = event.data || {};
                        if (data.channel !== state.channel) {
                            return;
                        }
                        if (data.type === 'ucp.embedded.ping') {
                            emit('ucp.embedded.pong');
                        }
                        if (data.type === 'ucp.embedded.refresh') {
                            emit('ucp.embedded.state');
                        }
                    });
                    emit('ucp.embedded.ready');
                    emit('ucp.embedded.state');
                })();
                </script>
                </body>
                </html>
                HTML,
            $this->escape($this->title($type)),
            $this->escape($type),
            $this->escape($this->title($type)),
            $this->escape($id),
            $this->statusBadge($data),
            $this->lineItems($data),
            $this->totals($data),
            $this->continueLink($data),
            json_encode($state, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_AMP | \JSON_HEX_QUOT),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function lineItems(array $data): string
    {
        $lineItems = \is_array($data['line_items'] ?? null) ? $data['line_items'] : [];
        if ([] === $lineItems) {
            return '<p class="empty">No line items.</p>';
        }

        $rows = '';
        foreach ($lineItems as $lineItem) {
            if (!\is_array($lineItem)) {
                continue;
            }

            $item = \is_array($lineItem['item'] ?? null) ? $lineItem['item'] : [];
            $title = (string) ($item['title'] ?? $item['id'] ?? 'Item');
            $quantity = (int) ($lineItem['quantity'] ?? 1);
            $price = isset($item['price']) && is_numeric($item['price']) ? (float) $item['price'] : null;
            $displayPrice = null !== $price ? number_format($price * $quantity, 2) : '';

            $rows .= '<tr><td>'.$this->escape($title).'</td><td>'.$this->escape((string) $quantity).'</td><td>'.$this->escape($displayPrice).'</td></tr>';
        }

        return '<table><thead><tr><th>Item</th><th>Qty</th><th>Total</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function totals(array $data): string
    {
        $totals = \is_array($data['totals'] ?? null) ? $data['totals'] : [];
        if ([] === $totals) {
            return '';
        }

        $rows = '';
        foreach ($totals as $total) {
            if (!\is_array($total)) {
                continue;
            }

            $label = ucfirst(str_replace('_', ' ', (string) ($total['type'] ?? 'total')));
            $display = (string) ($total['display_text'] ?? $total['amount'] ?? '');
            $rows .= '<div class="total"><span>'.$this->escape($label).'</span><span>'.$this->escape($display).'</span></div>';
        }

        return '<div class="totals">'.$rows.'</div>';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function statusBadge(array $data): string
    {
        if (!\is_string($data['status'] ?? null) || '' === $data['status']) {
            return '';
        }

        return '<span class="status">'.$this->escape($data['status']).'</span>';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function continueLink(array $data): string
    {
        if (!\is_string($data['continue_url'] ?? null) || '' === $data['continue_url']) {
            return '';
        }

        return '<a class="cta" target="_top" rel="noopener" href="'.$this->escape($data['continue_url']).'">Continue checkout</a>';
    }

    private function title(string $type): string
    {
        return 'checkout' === $type ? 'Checkout session' : 'Cart';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
