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
    private SigningKeyManagerInterface $signingKeyManager;

    protected function setUp(): void
    {
        $this->signingKeyManager = new UcpSigningKeyServiceTestSigningKeyManager();
    }

    #[Test]
    public function testItDeletesOnlyTheRequestedManagedKey(): void
    {
        $activeKey = new ManagedSigningKey('active-key', 'public', 'private');
        $retiredKey = new ManagedSigningKey('retired-key', 'public', 'private', status: 'retired', retireAt: '2026-01-01T00:00:00+00:00');

        $repository = new UcpSigningKeyServiceTestRepository(['active-key' => $activeKey, 'retired-key' => $retiredKey]);

        $service = new UcpSigningKeyService($repository, $this->signingKeyManager);

        self::assertTrue($service->delete(null, 'active-key'));
        self::assertNull($repository->findManaged('active-key'));
        self::assertNotNull($repository->findManaged('retired-key'));
    }

    #[Test]
    public function testItScopesKeysBySalesChannelWhenTheRepositorySupportsTenants(): void
    {
        $repository = new UcpSigningKeyServiceTestTenantRepository();

        $service = new UcpSigningKeyService($repository, $this->signingKeyManager);

        $service->create('sales-channel-a', 'shared-kid');
        $service->create('sales-channel-b', 'shared-kid');

        self::assertCount(1, $service->all('sales-channel-a'));
        self::assertCount(1, $service->all('sales-channel-b'));
        self::assertTrue($service->delete('sales-channel-a', 'shared-kid'));
        self::assertSame([], $service->all('sales-channel-a'));
        self::assertCount(1, $service->all('sales-channel-b'));
    }
}

/**
 * @internal
 */
final class UcpSigningKeyServiceTestSigningKeyManager implements SigningKeyManagerInterface
{
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
}

/**
 * @internal
 */
class UcpSigningKeyServiceTestRepository implements ManagedSigningKeyRepositoryInterface
{
    /** @var array<string, array<string, ManagedSigningKey>> */
    protected array $tenantKeys = [];

    /**
     * @param array<string, ManagedSigningKey> $keys
     */
    public function __construct(array $keys = [])
    {
        $this->tenantKeys[''] = $keys;
    }

    public function saveManaged(ManagedSigningKey $key): void
    {
        $this->saveForTenant(null, $key);
    }

    public function findManaged(string $kid): ?ManagedSigningKey
    {
        return $this->findForTenant(null, $kid);
    }

    public function deleteManaged(string $kid): bool
    {
        return $this->deleteForTenant(null, $kid);
    }

    public function allManaged(): array
    {
        return $this->allForTenant(null);
    }

    public function active(): array
    {
        return $this->activeForTenantKey(null);
    }

    public function purgeRetired(string $olderThanIso8601): void
    {
    }

    protected function saveForTenant(?string $tenantIdentifier, ManagedSigningKey $key): void
    {
        $this->tenantKeys[$tenantIdentifier ?? ''][$key->kid] = $key;
    }

    protected function findForTenant(?string $tenantIdentifier, string $kid): ?ManagedSigningKey
    {
        return $this->tenantKeys[$tenantIdentifier ?? ''][$kid] ?? null;
    }

    protected function deleteForTenant(?string $tenantIdentifier, string $kid): bool
    {
        if (!isset($this->tenantKeys[$tenantIdentifier ?? ''][$kid])) {
            return false;
        }

        unset($this->tenantKeys[$tenantIdentifier ?? ''][$kid]);

        return true;
    }

    /**
     * @return list<ManagedSigningKey>
     */
    protected function allForTenant(?string $tenantIdentifier): array
    {
        return array_values($this->tenantKeys[$tenantIdentifier ?? ''] ?? []);
    }

    /**
     * @return list<ManagedSigningKey>
     */
    protected function activeForTenantKey(?string $tenantIdentifier): array
    {
        return array_values(array_filter(
            $this->tenantKeys[$tenantIdentifier ?? ''] ?? [],
            static fn (ManagedSigningKey $key): bool => \in_array($key->status, ['active', 'retiring'], true),
        ));
    }
}

/**
 * @internal
 */
final class UcpSigningKeyServiceTestTenantRepository extends UcpSigningKeyServiceTestRepository implements TenantAwareManagedSigningKeyRepositoryInterface
{
    public function saveManagedForTenant(?string $tenantIdentifier, ManagedSigningKey $key): void
    {
        $this->saveForTenant($tenantIdentifier, $key);
    }

    public function findManagedForTenant(?string $tenantIdentifier, string $kid): ?ManagedSigningKey
    {
        return $this->findForTenant($tenantIdentifier, $kid);
    }

    public function deleteManagedForTenant(?string $tenantIdentifier, string $kid): bool
    {
        return $this->deleteForTenant($tenantIdentifier, $kid);
    }

    public function allManagedForTenant(?string $tenantIdentifier): array
    {
        return $this->allForTenant($tenantIdentifier);
    }

    public function activeForTenant(?string $tenantIdentifier): array
    {
        return $this->activeForTenantKey($tenantIdentifier);
    }
}
