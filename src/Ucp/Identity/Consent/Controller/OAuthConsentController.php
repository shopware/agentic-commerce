<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity\Consent\Controller;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\Ucp\Identity\Consent\CustomerConsentService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Ucp\Sdk\Exception\OAuthException;

/**
 * Browser-facing authorization endpoint for identity linking.
 *
 * This is what the OAuth metadata advertises as `authorization_endpoint`, and it
 * is the piece that makes the flow walkable by a real person: a customer who is
 * not logged in is sent to the storefront login and returned here, then sees who
 * is asking for which permissions, and only their approval mints a code.
 *
 * Deliberately outside the `/ucp/` prefix - the SDK's request-context listener
 * demands a `UCP-Agent` header below it, which a browser never sends.
 *
 * @internal
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
#[Package('framework')]
final class OAuthConsentController
{
    public function __construct(
        private readonly CustomerConsentService $consentService,
        private readonly Environment $twig,
        private readonly RouterInterface $router,
    ) {
    }

    #[Route(
        path: CustomerConsentService::CONSENT_PATH,
        name: 'frontend.ucp.consent',
        methods: ['GET'],
        defaults: ['_noStore' => true],
    )]
    public function consent(Request $request, SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();
        if (null === $customer) {
            return $this->redirectToLogin($request);
        }

        try {
            $consentRequest = $this->consentService->parse($request->query->all(), $context->getSalesChannelId());
        } catch (OAuthException $exception) {
            return $this->renderError($exception->getMessage());
        }

        return new Response($this->twig->render('@SwagAgenticCommerce/ucp/identity/consent.html.twig', [
            'clientHost' => $consentRequest->clientHost(),
            'clientId' => $consentRequest->clientId,
            'scopes' => $this->consentService->describeScopes($consentRequest),
            'customerEmail' => $customer->getEmail(),
            'shopName' => $context->getSalesChannel()->getName(),
            'formAction' => CustomerConsentService::CONSENT_PATH,
            'parameters' => $consentRequest->toQueryParameters(),
        ]), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route(
        path: CustomerConsentService::CONSENT_PATH,
        name: 'frontend.ucp.consent.submit',
        methods: ['POST'],
        defaults: ['_noStore' => true],
    )]
    public function submit(Request $request, SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();
        if (null === $customer) {
            return $this->redirectToLogin($request);
        }

        try {
            $consentRequest = $this->consentService->parse($request->request->all(), $context->getSalesChannelId());
        } catch (OAuthException $exception) {
            return $this->renderError($exception->getMessage());
        }

        if ('approve' !== $request->request->get('action')) {
            return new RedirectResponse($this->consentService->deny($consentRequest));
        }

        return new RedirectResponse($this->consentService->approve(
            $consentRequest,
            $customer->getId(),
            $context->getSalesChannelId(),
            $this->issuer($request),
        ));
    }

    /**
     * Sends the customer through the storefront login and back to this request,
     * using Shopware's own redirect-after-login mechanism.
     */
    private function redirectToLogin(Request $request): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('frontend.account.login.page', [
            'redirectTo' => 'frontend.ucp.consent',
            'redirectParameters' => json_encode($request->query->all(), \JSON_THROW_ON_ERROR),
        ], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    /**
     * An invalid request is never redirected back: a bad client id or redirect URI
     * is exactly what must not be used as a redirect target.
     */
    private function renderError(string $message): Response
    {
        return new Response($this->twig->render('@SwagAgenticCommerce/ucp/identity/consent-error.html.twig', [
            'message' => $message,
        ]), Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
    }

    private function issuer(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }
}
