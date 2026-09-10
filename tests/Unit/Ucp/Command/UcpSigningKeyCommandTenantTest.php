<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Tests\Unit\UcpSigningKeyServiceTestSigningKeyManager;
use Swag\AgenticCommerce\Tests\Unit\UcpSigningKeyServiceTestTenantRepository;
use Swag\AgenticCommerce\Ucp\Command\InteractsWithSalesChannelTenant;
use Swag\AgenticCommerce\Ucp\Command\SalesChannelResolver;
use Swag\AgenticCommerce\Ucp\Command\UcpSigningKeyGenerateCommand;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the sales-channel -> tenant mapping the plugin subclasses add on top of
 * the SDK signing-key commands (via {@see InteractsWithSalesChannelTenant}).
 *
 * @internal
 */
#[CoversClass(UcpSigningKeyGenerateCommand::class)]
#[CoversClass(InteractsWithSalesChannelTenant::class)]
class UcpSigningKeyCommandTenantTest extends TestCase
{
    private const MUSIC_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';
    private const STORE_ID = '0191bbbbbbbb7000bbbbbbbbbbbbbbbb';

    public function testItResolvesTheSalesChannelByNameToTheTenant(): void
    {
        $repository = new UcpSigningKeyServiceTestTenantRepository();
        $command = $this->generateCommand($repository);

        $status = (new CommandTester($command))->execute(
            ['--sales-channel' => 'Music', '--kid' => 'k1'],
            ['interactive' => false],
        );

        static::assertSame(0, $status);
        static::assertNotNull(
            $repository->findManagedForTenant(self::MUSIC_ID, 'k1'),
            'The key must be stored under the resolved sales-channel id',
        );
        static::assertNull(
            $repository->findManagedForTenant(self::STORE_ID, 'k1'),
            'The key must not leak into another sales channel',
        );
        static::assertNull(
            $repository->findManagedForTenant(null, 'k1'),
            'The key must not land in the global scope',
        );
    }

    public function testItResolvesTheSalesChannelById(): void
    {
        $repository = new UcpSigningKeyServiceTestTenantRepository();
        $command = $this->generateCommand($repository);

        $status = (new CommandTester($command))->execute(
            ['--sales-channel' => self::STORE_ID, '--kid' => 'k1'],
            ['interactive' => false],
        );

        static::assertSame(0, $status);
        static::assertNotNull($repository->findManagedForTenant(self::STORE_ID, 'k1'));
    }

    public function testItAbortsWhenTheSalesChannelCannotBeResolved(): void
    {
        $repository = new UcpSigningKeyServiceTestTenantRepository();
        $command = $this->generateCommand($repository);

        $this->expectException(RuntimeException::class);

        try {
            (new CommandTester($command))->execute(
                ['--sales-channel' => 'does-not-exist', '--kid' => 'k1'],
                ['interactive' => false],
            );
        } finally {
            static::assertNull($repository->findManagedForTenant(self::MUSIC_ID, 'k1'));
            static::assertNull($repository->findManagedForTenant(null, 'k1'));
        }
    }

    public function testItFallsBackToTheGlobalScopeWhenOmittedAndNonInteractive(): void
    {
        $repository = new UcpSigningKeyServiceTestTenantRepository();
        $command = $this->generateCommand($repository);

        $status = (new CommandTester($command))->execute(
            ['--kid' => 'k1'],
            ['interactive' => false],
        );

        static::assertSame(0, $status);
        static::assertNotNull(
            $repository->findManagedForTenant(null, 'k1'),
            'Omitting --sales-channel targets the global/default scope',
        );
        static::assertNull($repository->findManagedForTenant(self::MUSIC_ID, 'k1'));
    }

    private function generateCommand(UcpSigningKeyServiceTestTenantRepository $repository): UcpSigningKeyGenerateCommand
    {
        return new UcpSigningKeyGenerateCommand(
            $this->resolver(),
            new UcpSigningKeyServiceTestSigningKeyManager(),
            $repository,
        );
    }

    private function resolver(): SalesChannelResolver
    {
        $collection = new SalesChannelCollection([
            $this->salesChannel(self::MUSIC_ID, 'Music'),
            $this->salesChannel(self::STORE_ID, 'Storefront'),
        ]);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($collection);

        /** @var EntityRepository<SalesChannelCollection>&\PHPUnit\Framework\MockObject\MockObject $repository */
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($searchResult);

        return new SalesChannelResolver(new SalesChannelViewProvider(
            $repository,
            new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Storefront),
        ));
    }

    private function salesChannel(string $id, string $name): SalesChannelEntity
    {
        $entity = new SalesChannelEntity();
        $entity->setId($id);
        $entity->setUniqueIdentifier($id);
        $entity->setName($name);
        $entity->setTypeId('0191cccccccc7000cccccccccccccccc');

        return $entity;
    }
}
