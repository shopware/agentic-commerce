<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/** @internal */
final class UcpSigningKeyServiceTest extends TestCase
{
    #[Test]
    public function testItDeletesOnlyTheRequestedManagedKey(): void
    {
        $activeKey = new ManagedSigningKey('active-key', 'public', 'private');
        $retiredKey = new ManagedSigningKey('retired-key', 'public', 'private', status: 'retired', retireAt: '2026-01-01T00:00:00+00:00');

        $repository = new class(['active-key' => $activeKey, 'retired-key' => $retiredKey]) implements ManagedSigningKeyRepositoryInterface {
            /**
             * @param array<string, ManagedSigningKey> $keys
             */
            public function __construct(
                public array $keys,
            ) {
            }

            public function saveManaged(ManagedSigningKey $key): void
            {
                $this->keys[$key->kid] = $key;
            }

            public function findManaged(string $kid): ?ManagedSigningKey
            {
                return $this->keys[$kid] ?? null;
            }

            public function allManaged(): array
            {
                return array_values($this->keys);
            }

            public function active(): array
            {
                return array_values(array_filter(
                    $this->keys,
                    static fn (ManagedSigningKey $key): bool => \in_array($key->status, ['active', 'retiring'], true),
                ));
            }

            public function purgeRetired(string $olderThanIso8601): void
            {
            }

            public function deleteManaged(string $kid): bool
            {
                if (!isset($this->keys[$kid])) {
                    return false;
                }

                unset($this->keys[$kid]);

                return true;
            }
        };

        $signingKeyManager = new class implements SigningKeyManagerInterface {
            public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey
            {
                return new ManagedSigningKey($kid, 'public', 'private', $algorithm);
            }

            public function toPublicKey(ManagedSigningKey $key): PublicSigningKey
            {
                return new PublicSigningKey($key->kid, $key->algorithm);
            }

            public function publicKeyFromJwk(array $jwk): PublicSigningKey
            {
                return PublicSigningKey::fromJwk($jwk);
            }
        };

        $service = new UcpSigningKeyService($repository, $signingKeyManager);

        self::assertTrue($service->delete(null, 'active-key'));
        self::assertNull($repository->findManaged('active-key'));
        self::assertNotNull($repository->findManaged('retired-key'));
    }

    #[Test]
    public function testItScopesKeysBySalesChannelWhenTheRepositorySupportsTenants(): void
    {
        $repository = new class implements ManagedSigningKeyRepositoryInterface, TenantAwareManagedSigningKeyRepositoryInterface {
            /** @var array<string, array<string, ManagedSigningKey>> */
            public array $tenantKeys = [];

            public function saveManaged(ManagedSigningKey $key): void
            {
                $this->saveManagedForTenant(null, $key);
            }

            public function findManaged(string $kid): ?ManagedSigningKey
            {
                return $this->findManagedForTenant(null, $kid);
            }

            public function deleteManaged(string $kid): bool
            {
                return $this->deleteManagedForTenant(null, $kid);
            }

            public function allManaged(): array
            {
                return $this->allManagedForTenant(null);
            }

            public function active(): array
            {
                return $this->activeForTenant(null);
            }

            public function purgeRetired(string $olderThanIso8601): void
            {
            }

            public function saveManagedForTenant(?string $tenantIdentifier, ManagedSigningKey $key): void
            {
                $this->tenantKeys[$tenantIdentifier ?? ''][$key->kid] = $key;
            }

            public function findManagedForTenant(?string $tenantIdentifier, string $kid): ?ManagedSigningKey
            {
                return $this->tenantKeys[$tenantIdentifier ?? ''][$kid] ?? null;
            }

            public function deleteManagedForTenant(?string $tenantIdentifier, string $kid): bool
            {
                if (!isset($this->tenantKeys[$tenantIdentifier ?? ''][$kid])) {
                    return false;
                }

                unset($this->tenantKeys[$tenantIdentifier ?? ''][$kid]);

                return true;
            }

            public function allManagedForTenant(?string $tenantIdentifier): array
            {
                return array_values($this->tenantKeys[$tenantIdentifier ?? ''] ?? []);
            }

            public function activeForTenant(?string $tenantIdentifier): array
            {
                return array_values(array_filter(
                    $this->tenantKeys[$tenantIdentifier ?? ''] ?? [],
                    static fn (ManagedSigningKey $key): bool => \in_array($key->status, ['active', 'retiring'], true),
                ));
            }
        };

        $service = new UcpSigningKeyService($repository, $this->signingKeyManager());

        $service->create('sales-channel-a', 'shared-kid');
        $service->create('sales-channel-b', 'shared-kid');

        self::assertCount(1, $service->all('sales-channel-a'));
        self::assertCount(1, $service->all('sales-channel-b'));
        self::assertTrue($service->delete('sales-channel-a', 'shared-kid'));
        self::assertSame([], $service->all('sales-channel-a'));
        self::assertCount(1, $service->all('sales-channel-b'));
    }

    private function signingKeyManager(): SigningKeyManagerInterface
    {
        return new class implements SigningKeyManagerInterface {
            public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey
            {
                return new ManagedSigningKey($kid, 'public', 'private', $algorithm);
            }

            public function toPublicKey(ManagedSigningKey $key): PublicSigningKey
            {
                return new PublicSigningKey($key->kid, $key->algorithm);
            }

            public function publicKeyFromJwk(array $jwk): PublicSigningKey
            {
                return PublicSigningKey::fromJwk($jwk);
            }
        };
    }
}
