<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Ucp\Sdk\Enum\UcpCapability;

/**
 * Every id this plugin publishes must be one the specification defines.
 *
 * Two invented ids reached production and stayed: `dev.ucp.shopping.catalog`, which no
 * release has ever defined, and `dev.ucp.shopping.payment_tokenization`, which describes a
 * payment handler rather than a shopping capability. Negotiation matches by string, so an id
 * nobody else knows is one that silently never matches -- and the symptom is an empty result
 * rather than a refused capability, which is why both survived so long.
 *
 * The SDK's `UcpCapability` enum is generated from the pinned schema trees and is guarded on
 * that side against drifting from them, so it is the closest thing to the specification this
 * plugin can assert against without shipping its own copy of the schemas.
 *
 * A capability may legitimately not be published -- this plugin does not implement
 * fulfillment or buyer consent -- so this is one-directional: everything published must be
 * known, not everything known must be published.
 */
final class PublishedCapabilityIdsTest extends TestCase
{
    /**
     * Ids this plugin keeps for its own use and deliberately does not publish, with the
     * reason. An entry here is a claim that the id is not advertised to any peer.
     *
     * @var array<string, string>
     */
    private const NOT_PUBLISHED = [
        // Kept as the switch that decides whether payment handlers are published at all.
        // Not a capability: at 2026-08-25 tokenization lives in handlers/tokenization.
        UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION => 'local switch for publishing payment handlers',
    ];

    #[Test]
    public function everyDescriptorThisPluginPublishesIsDefinedByTheSpecification(): void
    {
        $known = array_map(
            static fn (UcpCapability $capability): string => $capability->value,
            UcpCapability::cases(),
        );

        foreach (UcpCapabilityCatalog::allConfigKeys() as $configKey) {
            foreach (UcpCapabilityCatalog::descriptorNamesForConfigKey($configKey) as $descriptor) {
                if (isset(self::NOT_PUBLISHED[$descriptor])) {
                    continue;
                }

                self::assertContains(
                    $descriptor,
                    $known,
                    \sprintf(
                        'Capability id "%s" (config key "%s") is not one the specification defines, so no peer can '
                        .'negotiate on it and every operation behind it is refused as outside the intersection. '
                        .'Either use the id the release names, or add it to NOT_PUBLISHED with the reason it is local.',
                        $descriptor,
                        $configKey,
                    ),
                );
            }
        }
    }

    /**
     * The other half: an id excused from publication has to actually be excused. Without
     * this, NOT_PUBLISHED would be a way to silence the check above rather than a record of
     * a deliberate exception.
     */
    #[Test]
    public function everyUnpublishedIdIsOneTheSpecificationDoesNotDefine(): void
    {
        $known = array_map(
            static fn (UcpCapability $capability): string => $capability->value,
            UcpCapability::cases(),
        );

        foreach (self::NOT_PUBLISHED as $descriptor => $reason) {
            self::assertNotContains(
                $descriptor,
                $known,
                \sprintf(
                    '"%s" is defined by the specification, so it should be published rather than excused as "%s".',
                    $descriptor,
                    $reason,
                ),
            );
        }
    }

    /**
     * Both catalog operations are advertised. Publishing only one of them is the shape the
     * umbrella id collapsed into, and it leaves the other unable to negotiate.
     */
    #[Test]
    public function bothCatalogOperationsArePublished(): void
    {
        $descriptors = UcpCapabilityCatalog::descriptorNamesForConfigKey(UcpCapabilityCatalog::CONFIG_CATALOG);

        self::assertContains(UcpCapability::CatalogSearch->value, $descriptors);
        self::assertContains(UcpCapability::CatalogLookup->value, $descriptors);
    }
}
