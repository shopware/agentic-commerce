<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\StaticSalesChannelContextService;
use Swag\AgenticCommerce\Ucp\Identity\AccessTokenReaderInterface;
use Swag\AgenticCommerce\Ucp\Identity\AgentCustomerAuthenticator;
use Swag\AgenticCommerce\Ucp\Identity\AgentCustomerCredential;
use Swag\AgenticCommerce\Ucp\Identity\OAuthAccessTokenInfo;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(AgentCustomerAuthenticator::class)]
#[CoversClass(AgentCustomerCredential::class)]
#[CoversClass(OAuthAccessTokenInfo::class)]
final class AgentCustomerAuthenticatorTest extends TestCase
{
    private const REQUIRED_SCOPE = 'com.shopware.quote:manage';

    #[Test]
    public function testItResolvesTheCustomerNamedByTheAccessTokenSubject(): void
    {
        $customerContext = $this->customerContext('customer-id');

        $authenticator = new AgentCustomerAuthenticator(
            $this->resolver($customerContext),
            $this->reader($this->tokenInfo([self::REQUIRED_SCOPE])),
            new ContextTokenGenerator(),
        );

        // The subject of the token decides the customer; no customer session is
        // presented by the agent at all.
        self::assertSame($customerContext, $authenticator->authenticate(
            AgentCustomerCredential::fromAccessToken('ucp_access_valid'),
            $this->requestContext(),
            self::REQUIRED_SCOPE,
        ));
        self::assertSame('customer-id', $customerContext->getCustomer()?->getId());
    }

    #[Test]
    public function testItRejectsAnUnknownOrRevokedAccessToken(): void
    {
        $authenticator = new AgentCustomerAuthenticator($this->resolver($this->customerContext('customer-id')), $this->reader(null), new ContextTokenGenerator());

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/invalid, expired, or revoked/');
        $authenticator->authenticate(
            AgentCustomerCredential::fromAccessToken('ucp_access_gone'),
            $this->requestContext(),
            self::REQUIRED_SCOPE,
        );
    }

    #[Test]
    public function testItRejectsAnAccessTokenWithoutTheRequiredScope(): void
    {
        $authenticator = new AgentCustomerAuthenticator(
            $this->resolver($this->customerContext('customer-id')),
            $this->reader($this->tokenInfo(['dev.ucp.shopping.cart:manage'])),
            new ContextTokenGenerator(),
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/missing the required scope/');
        $authenticator->authenticate(
            AgentCustomerCredential::fromAccessToken('ucp_access_wrong_scope'),
            $this->requestContext(),
            self::REQUIRED_SCOPE,
        );
    }

    #[Test]
    public function testItNeverAcceptsAnEmptyCredential(): void
    {
        $authenticator = new AgentCustomerAuthenticator($this->resolver($this->customerContext('customer-id')), $this->reader(null), new ContextTokenGenerator());

        $this->expectException(ValidationException::class);
        $authenticator->authenticate(
            AgentCustomerCredential::fromContextToken(''),
            $this->requestContext(),
            self::REQUIRED_SCOPE,
        );
    }

    #[Test]
    public function testItRejectsAContextTokenThatIdentifiesNoCustomer(): void
    {
        $authenticator = new AgentCustomerAuthenticator(
            $this->resolver($this->customerContext(null)),
            $this->reader(null),
            new ContextTokenGenerator(),
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/does not identify a customer/');
        $authenticator->authenticate(
            AgentCustomerCredential::fromContextToken('guest-context-token'),
            $this->requestContext(),
        );
    }

    #[Test]
    public function testItStillAcceptsACustomerContextTokenForUnscopedOperations(): void
    {
        $customerContext = $this->customerContext('customer-id');

        $authenticator = new AgentCustomerAuthenticator(
            $this->resolver($customerContext),
            $this->reader(null),
            new ContextTokenGenerator(),
        );

        self::assertSame($customerContext, $authenticator->authenticate(
            AgentCustomerCredential::fromContextToken('customer-context-token'),
            $this->requestContext(),
        ));
    }

    /**
     * @param list<string> $scopes
     */
    private function tokenInfo(array $scopes): OAuthAccessTokenInfo
    {
        return new OAuthAccessTokenInfo('sales-channel-id', 'https://agent.example/.well-known/ucp', 'customer-id', $scopes);
    }

    private function reader(?OAuthAccessTokenInfo $token): AccessTokenReaderInterface
    {
        $reader = $this->createMock(AccessTokenReaderInterface::class);
        $reader->method('findAccessToken')->willReturn($token);

        return $reader;
    }

    /**
     * A real resolver over test doubles, the way the gateway tests build it: the
     * resolver is final by design, and reflection-mocking it would hide the very
     * wiring under test.
     */
    private function resolver(SalesChannelContext $context): SalesChannelContextResolver
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('domain-id');
        $domain->setUrl('https://merchant.example');
        $domain->setSalesChannelId('sales-channel-id');
        $domain->setLanguageId('language-id');
        $domain->setCurrencyId('currency-id');

        /** @var EntityRepository<SalesChannelDomainCollection>&MockObject $domainRepository */
        $domainRepository = $this->createMock(EntityRepository::class);
        $domainRepository->method('search')->willReturn(new EntitySearchResult(
            'sales_channel_domain',
            1,
            new SalesChannelDomainCollection([$domain]),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        ));

        return new SalesChannelContextResolver(
            new SalesChannelDomainResolver($domainRepository),
            new StaticSalesChannelContextService($context),
            $this->createMock(SalesChannelContextPersister::class),
        );
    }

    private function customerContext(?string $customerId): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);

        if (null === $customerId) {
            $context->method('getCustomer')->willReturn(null);

            return $context;
        }

        $customer = new CustomerEntity();
        $customer->setId($customerId);
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }

    private function requestContext(): RequestContext
    {
        return new RequestContext('merchant.example');
    }
}
