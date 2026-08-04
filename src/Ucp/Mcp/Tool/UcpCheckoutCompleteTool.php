<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-checkout-complete', title: 'UCP Checkout Complete', description: 'Complete a checkout session through the shared UCP checkout capability. This places the order and takes payment. With dryRun=true (the default) nothing is placed: the current checkout is read back and reported together with anything that would block a commit. Set dryRun=false only once the buyer has confirmed the purchase.')]
/** @internal */
#[Package('checkout')]
final class UcpCheckoutCompleteTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
        private readonly UcpCheckoutCompletionPreview $completionPreview,
    ) {
    }

    public function __invoke(string $id, bool $dryRun = true): string
    {
        try {
            return $this->toolContext->executeMutating(
                'checkout.complete',
                ['id' => $id],
                fn (RequestContext $context) => $this->operationExecutor->execute(new ShoppingOperationRequest(
                    'checkout.complete',
                    [],
                    $context,
                    $id,
                )),
                $dryRun,
                fn (RequestContext $context) => $this->preview($id, $context),
            );
        } catch (\Throwable $exception) {
            return $this->toolContext->failure($exception);
        }
    }

    /**
     * Reads the checkout back and reports what completing it would do.
     *
     * Unlike the other mutating tools this cannot run the operation inside a
     * rolled-back transaction: completion synchronously POSTs an `order.created`
     * webhook to the merchant, and a rollback does not recall an HTTP request. The
     * preview is therefore built from the read-only `checkout.get` path — the same
     * one shopware-ucp-checkout-get uses — plus the blockers its status implies.
     *
     * It is still handed to executeMutating() rather than called ahead of it, so a
     * preview fails the same validation a commit would; and the context arrives from
     * there already checked instead of being resolved again here.
     */
    private function preview(string $id, RequestContext $context): string
    {
        $checkout = $this->operationExecutor->execute(new ShoppingOperationRequest(
            'checkout.get',
            [],
            $context,
            $id,
        ));

        return $this->toolContext->preview(
            'checkout.complete',
            $checkout,
            $this->completionPreview->blockers($checkout->jsonSerialize()['status'] ?? null),
        );
    }
}
