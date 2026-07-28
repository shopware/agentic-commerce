<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Identity\AgentCustomerAuthenticator;
use Swag\AgenticCommerce\Ucp\Identity\AgentCustomerCredential;
use Swag\AgenticCommerce\Ucp\Identity\ShopwareIdentityLinkingAdapter;
use Swag\AgenticCommerce\Ucp\Quote\QuoteBackendFeature;
use Swag\AgenticCommerce\Ucp\Quote\QuoteGatewayInterface;
use Swag\AgenticCommerce\Ucp\Quote\QuoteList;
use Swag\AgenticCommerce\Ucp\Quote\QuoteSnapshot;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * Buyer-facing quote flows on top of the commercial B2B Store API routes.
 *
 * The commercial plugin stays authoritative for all quote logic (pricing, state
 * machine, ownership): every operation calls its Store API routes in the
 * resolved customer's sales-channel context, so contract prices and rules behave
 * exactly as if the customer acted themselves. The routes are typed as `object`
 * because SwagCommercial is a runtime-detected soft dependency — this service is
 * only registered when its classes exist (see QuoteBackendFeature).
 *
 * @internal
 */
final class ShopwareQuoteGateway implements QuoteGatewayInterface
{
    /** Upper bound on a listing page, so an agent cannot ask for the whole table. */
    private const MAX_LIST_LIMIT = 50;

    public function __construct(
        private readonly AgentCustomerAuthenticator $authenticator,
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactory,
        private readonly object $quoteRequestRoute,
        private readonly object $quoteSendRequestRoute,
        private readonly object $quoteLineItemRoute,
        private readonly object $quoteLoadRoute,
        private readonly object $quoteListingRoute,
        private readonly object $quoteRequestChangeRoute,
        private readonly object $quoteDeclineRoute,
        private readonly object $quoteOrderRoute,
        private readonly ?object $customerSpecificFeatureService = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return QuoteBackendFeature::isLicensed();
    }

    public function requestQuote(AgentCustomerCredential $credential, array $lineItems, ?string $comment, RequestContext $requestContext): QuoteSnapshot
    {
        $context = $this->customerContext($credential, $requestContext);

        if ([] === $lineItems) {
            throw new ValidationException('A quote request needs at least one line item.', ['$.line_items must not be empty']);
        }

        $requestedPrices = [];
        /** @var list<LineItem> $items */
        $items = [];

        foreach ($lineItems as $index => $lineItem) {
            $productId = $this->requireProductId($lineItem, $index);
            $quantity = (int) ($lineItem['quantity'] ?? 1);

            if ($quantity < 1) {
                throw new ValidationException('Line item quantity must be a positive integer.', [\sprintf('$.line_items[%d].quantity must be >= 1', $index)]);
            }

            $items[] = $this->lineItemFactory->create(
                ['type' => LineItem::PRODUCT_LINE_ITEM_TYPE, 'referencedId' => $productId, 'quantity' => $quantity],
                $context,
            );

            $requestedPrice = $this->requestedPrice($lineItem, \sprintf('$.line_items[%d].requested_unit_price', $index));
            if (null !== $requestedPrice) {
                $requestedPrices[$productId] = $requestedPrice;
            }
        }

        // Deliberately through CartService rather than the item-add route directly:
        // the commercial quote route reads the cart back through CartService, which
        // caches per context token, so a cart filled around it would look empty to
        // the quote. CartService itself delegates to the Store API item-add route,
        // so the route boundary is still respected.
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $this->cartService->add($cart, $items, $context);

        $quote = $this->quoteRequestRoute->request($context)->getQuote();
        $quoteId = (string) $quote->getId();

        $this->applyRequestedPrices($quoteId, $quote, $requestedPrices, $context);

        $this->quoteSendRequestRoute->sendRequest($context, $quoteId, new RequestDataBag(['comment' => trim($comment ?? '')]));

        return $this->loadSnapshot($quoteId, $context);
    }

    public function getQuote(AgentCustomerCredential $credential, string $quoteId, RequestContext $requestContext): QuoteSnapshot
    {
        return $this->loadSnapshot($quoteId, $this->customerContext($credential, $requestContext));
    }

    public function listQuotes(AgentCustomerCredential $credential, int $limit, int $page, RequestContext $requestContext): QuoteList
    {
        $context = $this->customerContext($credential, $requestContext);

        $limit = max(1, min($limit, self::MAX_LIST_LIMIT));
        $page = max(1, $page);

        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->setOffset(($page - 1) * $limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        // The listing route only associates the currency, so state and line items
        // must be requested here or every entry would come back stateless - and
        // state is exactly what an agent polls this endpoint for.
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('lineItems');
        $criteria->getAssociation('lineItems')->addFilter(new EqualsFilter('deletedAt', null));
        $criteria->addAssociation('comments');

        $result = $this->quoteListingRoute->quotes($context, new Request(), $criteria)->getQuotes();

        $quotes = [];
        foreach ($result as $quote) {
            $quotes[] = $this->toSnapshot($quote);
        }

        return new QuoteList($quotes, $result->getTotal(), $limit, $page);
    }

    public function counterQuote(AgentCustomerCredential $credential, string $quoteId, array $lineItems, ?string $comment, RequestContext $requestContext): QuoteSnapshot
    {
        $context = $this->customerContext($credential, $requestContext);

        if ([] !== $lineItems) {
            $quote = $this->loadQuote($quoteId, $context);
            $this->applyCounterPrices($quoteId, $quote, $lineItems, $context);
        }

        $this->quoteRequestChangeRoute->requestChange($context, $quoteId, new RequestDataBag(['comment' => trim($comment ?? '')]));

        return $this->loadSnapshot($quoteId, $context);
    }

    public function acceptQuote(AgentCustomerCredential $credential, string $quoteId, RequestContext $requestContext): QuoteSnapshot
    {
        $context = $this->customerContext($credential, $requestContext);

        $order = $this->quoteOrderRoute->order($context, new RequestDataBag(), $quoteId)->getOrder();

        $snapshot = $this->loadSnapshot($quoteId, $context);

        return new QuoteSnapshot(
            $snapshot->id,
            $snapshot->quoteNumber,
            $snapshot->state,
            $snapshot->expirationDate,
            $snapshot->currency,
            $snapshot->totalGross,
            $snapshot->totalNet,
            $snapshot->taxStatus,
            $snapshot->lineItems,
            $snapshot->comments,
            (string) $order->getId(),
            $order->getOrderNumber(),
        );
    }

    public function declineQuote(AgentCustomerCredential $credential, string $quoteId, ?string $comment, RequestContext $requestContext): QuoteSnapshot
    {
        $context = $this->customerContext($credential, $requestContext);

        $this->quoteDeclineRoute->decline($context, $quoteId, new RequestDataBag(['comment' => trim($comment ?? '')]));

        return $this->loadSnapshot($quoteId, $context);
    }

    /**
     * Resolves the customer the agent acts for. The credential is the trust
     * boundary: an identity-linking access token names the customer and must
     * carry the quote scope, so the customer's recorded consent - not anything in
     * the request body - decides whose quotes are touched.
     */
    private function customerContext(AgentCustomerCredential $credential, RequestContext $requestContext): SalesChannelContext
    {
        if (!$this->isAvailable()) {
            throw new UnsupportedCapabilityException('Quote management is not licensed for this shop.');
        }

        $context = $this->authenticator->authenticate($credential, $requestContext, ShopwareIdentityLinkingAdapter::SCOPE_QUOTE_MANAGE);
        $customer = $context->getCustomer();

        if (null === $customer) {
            throw new ValidationException('Quote operations require a linked customer context.', ['$.headers.authorization must carry an identity-linking access token with the com.shopware.quote:manage scope']);
        }

        if (!$this->hasCustomerQuoteFeature($customer->getId())) {
            throw new UnsupportedCapabilityException('Quote management is not enabled for this customer.');
        }

        return $context;
    }

    private function hasCustomerQuoteFeature(string $customerId): bool
    {
        if (null === $this->customerSpecificFeatureService) {
            return false;
        }

        return true === $this->customerSpecificFeatureService->isAllowed($customerId, QuoteBackendFeature::CUSTOMER_FEATURE);
    }

    private function loadSnapshot(string $quoteId, SalesChannelContext $context): QuoteSnapshot
    {
        return $this->toSnapshot($this->loadQuote($quoteId, $context));
    }

    /**
     * The commercial load route filters by customer and sales channel, so a quote
     * belonging to somebody else is indistinguishable from one that does not
     * exist - its exception is translated to a not-found without confirming
     * existence.
     */
    private function loadQuote(string $quoteId, SalesChannelContext $context): object
    {
        $criteria = new Criteria();
        $criteria->addAssociation('lineItems');
        $criteria->getAssociation('lineItems')->addFilter(new EqualsFilter('deletedAt', null));
        $criteria->addAssociation('comments');

        try {
            $quote = $this->quoteLoadRoute->load($quoteId, $context, $criteria)->getQuote();
        } catch (\Throwable $exception) {
            throw new ResourceNotFoundException(\sprintf('Quote "%s" was not found for this customer.', $quoteId), previous: $exception);
        }

        return $quote;
    }

    private function toSnapshot(object $quote): QuoteSnapshot
    {
        return new QuoteSnapshot(
            (string) $quote->getId(),
            (string) $quote->getQuoteNumber(),
            $quote->getStateMachineState()?->getTechnicalName(),
            $quote->getExpirationDate()?->format(\DateTimeInterface::ATOM),
            $quote->getCurrency()?->getIsoCode(),
            $quote->getAmountTotal(),
            $quote->getAmountNet(),
            $quote->getTaxStatus(),
            $this->mapLineItems($quote),
            $this->mapComments($quote),
        );
    }

    /**
     * @return list<array{id: string, product_id: string|null, label: string, quantity: int, unit_price: float, total_price: float, requested_unit_price: float|null}>
     */
    private function mapLineItems(object $quote): array
    {
        $lineItems = [];

        foreach ($quote->getLineItems() ?? [] as $lineItem) {
            $lineItems[] = [
                'id' => (string) $lineItem->getId(),
                'product_id' => $lineItem->getProductId(),
                'label' => (string) $lineItem->getLabel(),
                'quantity' => (int) $lineItem->getQuantity(),
                // Per unit, in the quote currency; gross or net per totals.tax_status.
                'unit_price' => (float) $lineItem->getUnitPrice(),
                'total_price' => (float) $lineItem->getTotalPrice(),
                'requested_unit_price' => $lineItem->getRequestedPrice(),
            ];
        }

        return $lineItems;
    }

    /**
     * @return list<array{comment: string, author: string, created_at: string|null}>
     */
    private function mapComments(object $quote): array
    {
        $comments = [];

        foreach ($quote->getComments() ?? [] as $comment) {
            $comments[] = [
                'comment' => (string) $comment->getComment(),
                'author' => null !== $comment->getCustomerId() ? 'buyer' : 'merchant',
                'created_at' => $comment->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $comments;
    }

    /**
     * Buyer asks belong in `requestedPrice` (per unit) - never in a price
     * definition, which newer commercial versions rebuild from the catalog.
     *
     * @param array<string, float> $requestedPrices product id => requested unit price
     */
    private function applyRequestedPrices(string $quoteId, object $quote, array $requestedPrices, SalesChannelContext $context): void
    {
        if ([] === $requestedPrices) {
            return;
        }

        foreach ($quote->getLineItems() ?? [] as $lineItem) {
            $price = $requestedPrices[$lineItem->getProductId()] ?? null;
            if (null === $price) {
                continue;
            }

            $this->quoteLineItemRoute->edit($quoteId, (string) $lineItem->getId(), $context, new RequestDataBag(['requestedPrice' => $price]));
        }
    }

    /**
     * @param list<array{id?: string, product_id?: string, requested_unit_price?: float|int|string}> $lineItems
     */
    private function applyCounterPrices(string $quoteId, object $quote, array $lineItems, SalesChannelContext $context): void
    {
        $byId = [];
        $byProductId = [];

        foreach ($quote->getLineItems() ?? [] as $lineItem) {
            $byId[(string) $lineItem->getId()] = $lineItem;
            $productId = $lineItem->getProductId();
            if (null !== $productId) {
                $byProductId[$productId] = $lineItem;
            }
        }

        foreach ($lineItems as $index => $lineItem) {
            $price = $this->requestedPrice($lineItem, \sprintf('$.line_items[%d].requested_unit_price', $index));
            if (null === $price) {
                continue;
            }

            $target = $byId[$lineItem['id'] ?? ''] ?? $byProductId[$lineItem['product_id'] ?? ''] ?? null;
            if (null === $target) {
                throw new ValidationException('Counter-offer line item does not match any quote line item.', [\sprintf('$.line_items[%d] must reference an existing quote line item id or product id', $index)]);
            }

            $this->quoteLineItemRoute->edit($quoteId, (string) $target->getId(), $context, new RequestDataBag(['requestedPrice' => $price]));
        }
    }

    /**
     * @param array{product_id?: string, product_number?: string} $lineItem
     */
    private function requireProductId(array $lineItem, int $index): string
    {
        $productId = $lineItem['product_id'] ?? null;
        if (\is_string($productId) && '' !== $productId) {
            return $productId;
        }

        // Resolving a product number would need a catalog lookup; agents get the id
        // from the catalog capability, so v1 requires it explicitly.
        throw new ValidationException('Each quote line item needs a product id.', [\sprintf('$.line_items[%d].product_id is required', $index)]);
    }

    /**
     * @param array{requested_unit_price?: float|int|string} $lineItem
     */
    private function requestedPrice(array $lineItem, string $path): ?float
    {
        $requestedPrice = $lineItem['requested_unit_price'] ?? null;
        if (null === $requestedPrice || '' === $requestedPrice) {
            return null;
        }

        if (!is_numeric($requestedPrice)) {
            throw new ValidationException('Requested unit price must be numeric.', [$path.' must be a number']);
        }

        return (float) $requestedPrice;
    }
}
