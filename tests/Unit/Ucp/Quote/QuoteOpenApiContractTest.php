<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Quote;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\UcpProtocol;

/**
 * The published quote contract is a public BC surface: agents rely on the state
 * machine, the price semantics and the polling guidance it documents.
 *
 * @internal
 */
final class QuoteOpenApiContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        $file = \dirname(__DIR__, 4).'/src/Resources/schema/quote.openapi.json';
        self::assertFileExists($file);

        $contents = file_get_contents($file);
        self::assertIsString($contents);

        /** @var array<string, mixed> $schema */
        $schema = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        $this->schema = $schema;
    }

    #[Test]
    public function testItIsAnOpenApiDocumentForTheVendorCapability(): void
    {
        self::assertSame('3.1.0', $this->schema['openapi']);
        self::assertSame('com.shopware.quote', $this->schema['info']['title']);
        self::assertSame(UcpProtocol::VERSION, $this->schema['info']['version']);
    }

    #[Test]
    public function testItDocumentsEveryBuyerOperation(): void
    {
        $paths = $this->schema['paths'];

        self::assertArrayHasKey('post', $paths['/ucp/quotes']);
        self::assertArrayHasKey('get', $paths['/ucp/quotes']);
        self::assertArrayHasKey('get', $paths['/ucp/quotes/{id}']);
        self::assertArrayHasKey('post', $paths['/ucp/quotes/{id}/counter']);
        self::assertArrayHasKey('post', $paths['/ucp/quotes/{id}/accept']);
        self::assertArrayHasKey('post', $paths['/ucp/quotes/{id}/decline']);
    }

    #[Test]
    public function testItPublishesTheBuyerVisibleStateMachine(): void
    {
        $stateMachine = $this->schema['x-state-machine'];

        foreach (['open', 'in_review', 'replied', 'change_requested', 'accepted', 'declined', 'expired'] as $state) {
            self::assertArrayHasKey($state, $stateMachine['states'], \sprintf('missing state "%s"', $state));
        }

        self::assertSame(['accept', 'counter', 'decline'], $stateMachine['states']['replied']['buyer_actions']);
        self::assertNotEmpty(array_filter($stateMachine['transitions'], static fn (array $transition): bool => 'buyer' === $transition['actor']));
    }

    #[Test]
    public function testItDocumentsTheIdentityLinkedAuthorizationModel(): void
    {
        $schemes = $this->schema['components']['securitySchemes'];

        self::assertSame('sw-context-token', $schemes['contextToken']['name']);
        self::assertSame('UCP-Agent', $schemes['ucpAgent']['name']);
        self::assertStringContainsString('identity_linking', $this->schema['info']['description']);

        // The token names the customer, so no buyer claim travels in the body.
        self::assertArrayNotHasKey('Buyer', $this->schema['components']['schemas']);
        self::assertSame(['line_items'], $this->schema['components']['schemas']['QuoteRequest']['required']);
    }

    #[Test]
    public function testItDocumentsExpirationAndPriceSemantics(): void
    {
        self::assertIsInt($this->schema['x-polling-interval-seconds']);
        self::assertStringContainsString('expiration_date', $this->schema['info']['description']);
        self::assertStringContainsString('per unit', $this->schema['info']['description']);
        self::assertContains('expiration_date', $this->schema['components']['schemas']['Quote']['required']);
    }
}
