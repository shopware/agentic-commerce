<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Admin\SigningKey;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/** @internal */
#[Package('framework')]
final class UcpSigningKeyService
{
    public function __construct(
        private readonly ManagedSigningKeyRepositoryInterface $repository,
        private readonly SigningKeyManagerInterface $signingKeyManager,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(?string $salesChannelId = null): array
    {
        return array_map(
            fn (ManagedSigningKey $key): array => [
                'kid' => $key->kid,
                'algorithm' => $key->algorithm,
                'keyType' => $key->keyType,
                'use' => $key->use,
                'status' => $key->status,
                'curve' => $key->curve,
                'createdAt' => $key->createdAt,
                'retireAt' => $key->retireAt,
                'publicKey' => $this->signingKeyManager->toPublicKey($key)->toJwk(),
            ],
            $this->allManaged($salesChannelId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function create(?string $salesChannelId, ?string $kid = null, string $algorithm = 'ES256'): array
    {
        $key = $this->signingKeyManager->generate($kid ?: 'key-'.gmdate('YmdHis'), $algorithm);
        $this->saveManaged($salesChannelId, $key);

        return [
            'kid' => $key->kid,
            'algorithm' => $key->algorithm,
            'status' => $key->status,
            'createdAt' => $key->createdAt,
            'publicKey' => $this->signingKeyManager->toPublicKey($key)->toJwk(),
        ];
    }

    public function retire(?string $salesChannelId, string $kid): bool
    {
        $existing = $this->findManaged($salesChannelId, $kid);
        if (null === $existing) {
            return false;
        }

        $this->saveManaged($salesChannelId, new ManagedSigningKey(
            $existing->kid,
            $existing->publicKeyPem,
            $existing->privateKeyPem,
            $existing->algorithm,
            $existing->keyType,
            $existing->use,
            'retired',
            $existing->curve,
            $existing->createdAt,
            gmdate('c'),
        ));

        return true;
    }

    public function delete(?string $salesChannelId, string $kid): bool
    {
        if ($this->repository instanceof TenantAwareManagedSigningKeyRepositoryInterface) {
            return $this->repository->deleteManagedForTenant($salesChannelId, $kid);
        }

        return $this->repository->deleteManaged($kid);
    }

    /**
     * @return list<ManagedSigningKey>
     */
    private function allManaged(?string $salesChannelId): array
    {
        if ($this->repository instanceof TenantAwareManagedSigningKeyRepositoryInterface) {
            return $this->repository->allManagedForTenant($salesChannelId);
        }

        return $this->repository->allManaged();
    }

    private function findManaged(?string $salesChannelId, string $kid): ?ManagedSigningKey
    {
        if ($this->repository instanceof TenantAwareManagedSigningKeyRepositoryInterface) {
            return $this->repository->findManagedForTenant($salesChannelId, $kid);
        }

        return $this->repository->findManaged($kid);
    }

    private function saveManaged(?string $salesChannelId, ManagedSigningKey $key): void
    {
        if ($this->repository instanceof TenantAwareManagedSigningKeyRepositoryInterface) {
            $this->repository->saveManagedForTenant($salesChannelId, $key);

            return;
        }

        $this->repository->saveManaged($key);
    }
}
