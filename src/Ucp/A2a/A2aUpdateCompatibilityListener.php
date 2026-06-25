<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\A2a;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Exception\NegotiationException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[Package('checkout')]
final class A2aUpdateCompatibilityListener
{
    private const SUPPORTED_METHODS = [
        'cart.update' => true,
        'checkout.update' => true,
    ];

    public function __construct(
        private readonly HttpPayloadMapper $payloadMapper,
        private readonly RuntimeConfigurationResolverInterface $runtimeConfigurationResolver,
        private readonly ShoppingOperationExecutor $operationExecutor,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('/ucp/a2a' !== $request->getPathInfo() || !$request->isMethod(Request::METHOD_POST)) {
            return;
        }

        $rpcId = null;

        try {
            $payload = $this->payloadMapper->decode($request);
            $rpcId = $this->jsonRpcId($payload);
            $method = $this->jsonRpcMethod($payload);
            if (!isset(self::SUPPORTED_METHODS[$method])) {
                return;
            }

            // SDK A2A currently strips "id" from params before validating update
            // operations, while the generated schemas require it in the payload.
            $params = $this->jsonRpcParams($payload);
            $operationId = $this->operationId($params);
            $runtimeConfiguration = $this->runtimeConfigurationResolver->resolve($this->toHttpRequest($request));
            if (!\in_array(Transport::A2a, $runtimeConfiguration->transports, true)) {
                return;
            }

            $context = new RequestContext(
                parse_url($request->getUri(), \PHP_URL_HOST) ?: '',
                $this->headers($request),
                runtimeConfiguration: $runtimeConfiguration,
            );

            $result = $this->operationExecutor->execute(new ShoppingOperationRequest(
                $method,
                $params,
                $context,
                $operationId,
            ));

            $event->setResponse(new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => $rpcId,
                'result' => $result->toArray(),
            ]));
        } catch (BadRequestHttpException $exception) {
            $event->setResponse($this->jsonRpcError($rpcId, -32602, $exception->getMessage(), JsonResponse::HTTP_BAD_REQUEST));
        } catch (ValidationException|NegotiationException $exception) {
            $event->setResponse($this->jsonRpcError($rpcId, -32602, $exception->getMessage(), JsonResponse::HTTP_BAD_REQUEST));
        } catch (UnsupportedCapabilityException $exception) {
            $event->setResponse($this->jsonRpcError($rpcId, -32601, $exception->getMessage(), JsonResponse::HTTP_NOT_FOUND));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRpcId(array $payload): int|string|null
    {
        $id = $payload['id'] ?? null;
        if (null !== $id && !\is_int($id) && !\is_string($id)) {
            throw new BadRequestHttpException('JSON-RPC id must be a string, integer, or null.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRpcMethod(array $payload): string
    {
        if (($payload['jsonrpc'] ?? null) !== '2.0') {
            throw new BadRequestHttpException('JSON-RPC version must be "2.0".');
        }

        $method = $payload['method'] ?? null;
        if (!\is_string($method) || '' === $method) {
            throw new BadRequestHttpException('JSON-RPC method must be a non-empty string.');
        }

        return $method;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function jsonRpcParams(array $payload): array
    {
        $params = $payload['params'] ?? [];
        if (!\is_array($params) || (array_is_list($params) && [] !== $params)) {
            throw new BadRequestHttpException('JSON-RPC params must be an object.');
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function operationId(array $params): string
    {
        $id = $params['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new BadRequestHttpException('A2A operation requires a non-empty string id parameter.');
        }

        return $id;
    }

    private function jsonRpcError(int|string|null $id, int $code, string $message, int $statusCode): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $statusCode);
    }

    private function toHttpRequest(Request $request): HttpRequest
    {
        $query = $request->query->all();
        ksort($query);

        return new HttpRequest(
            $request->getMethod(),
            $request->getUri(),
            $this->headers($request),
            array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : (string) json_encode($value, \JSON_THROW_ON_ERROR), $query),
            '',
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $value) {
            $headers[$name] = implode(', ', array_map(static fn (?string $entry): string => (string) $entry, $value));
        }

        return $headers;
    }
}
