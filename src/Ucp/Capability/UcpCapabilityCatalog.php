<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

/** @internal */
final class UcpCapabilityCatalog
{
    public const CONFIG_CATALOG = 'catalog';
    public const CONFIG_CART = 'cart';
    public const CONFIG_DISCOUNT = 'discount';
    public const CONFIG_CHECKOUT = 'checkout';
    public const CONFIG_ORDER = 'order';
    public const CONFIG_IDENTITY_LINKING = 'identity_linking';
    public const CONFIG_PAYMENT_TOKENIZATION = 'payment_tokenization';

    /**
     * Two ids, because the specification defines two.
     *
     * There is no umbrella `dev.ucp.shopping.catalog` at any published release -- checked
     * against the pinned schema trees for both 2026-04-08 and 2026-08-25 -- so publishing
     * one matched nothing a conformant peer negotiates on, and every catalog operation was
     * refused as outside the negotiated intersection. It read as an empty catalogue rather
     * than as a rejected capability, which is a long way from the cause.
     */
    public const DESCRIPTOR_CATALOG_SEARCH = 'dev.ucp.shopping.catalog.search';
    public const DESCRIPTOR_CATALOG_LOOKUP = 'dev.ucp.shopping.catalog.lookup';
    public const DESCRIPTOR_CART = 'dev.ucp.shopping.cart';
    public const DESCRIPTOR_DISCOUNT = 'dev.ucp.shopping.discount';
    public const DESCRIPTOR_CHECKOUT = 'dev.ucp.shopping.checkout';
    public const DESCRIPTOR_ORDER = 'dev.ucp.shopping.order';
    public const DESCRIPTOR_IDENTITY_LINKING = 'dev.ucp.common.identity_linking';
    public const DESCRIPTOR_PAYMENT_TOKENIZATION = 'dev.ucp.shopping.payment_tokenization';

    /**
     * @return array<string, array{descriptors: non-empty-list<string>, path: string, specUrl?: string, schemaUrl?: string, extends?: list<string>}>
     */
    private static function definitions(): array
    {
        return [
            self::CONFIG_CATALOG => [
                'descriptors' => [self::DESCRIPTOR_CATALOG_SEARCH, self::DESCRIPTOR_CATALOG_LOOKUP],
                'path' => 'catalog',
            ],
            self::CONFIG_CART => ['descriptors' => [self::DESCRIPTOR_CART], 'path' => 'cart'],
            self::CONFIG_DISCOUNT => [
                'descriptors' => [self::DESCRIPTOR_DISCOUNT],
                'path' => 'discount',
                'extends' => [
                    self::DESCRIPTOR_CART,
                    self::DESCRIPTOR_CHECKOUT,
                ],
            ],
            self::CONFIG_CHECKOUT => ['descriptors' => [self::DESCRIPTOR_CHECKOUT], 'path' => 'checkout'],
            self::CONFIG_ORDER => ['descriptors' => [self::DESCRIPTOR_ORDER], 'path' => 'order'],
            self::CONFIG_IDENTITY_LINKING => [
                'descriptors' => [self::DESCRIPTOR_IDENTITY_LINKING],
                'path' => 'identity-linking',
                'specUrl' => 'https://ucp.dev/specification/identity-linking/',
                'schemaUrl' => UcpProtocol::schemaUrl('oauth', 'identity'),
            ],
            self::CONFIG_PAYMENT_TOKENIZATION => [
                'descriptors' => [self::DESCRIPTOR_PAYMENT_TOKENIZATION],
                'path' => 'payment-tokenization',
                'specUrl' => 'https://ucp.dev/specification/payment-token-exchange/',
                // schemaUrl falls back to UcpProtocol::schemaUrl('payment-tokenization') → shopping/payment-tokenization.json
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function defaultConfigKeys(): array
    {
        return [
            self::CONFIG_CATALOG,
            self::CONFIG_CART,
            self::CONFIG_DISCOUNT,
            self::CONFIG_CHECKOUT,
            self::CONFIG_ORDER,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allConfigKeys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @param list<string> $configKeys
     *
     * @return list<string>
     */
    public static function descriptorNamesForConfigKeys(array $configKeys): array
    {
        $descriptors = [];

        foreach ($configKeys as $configKey) {
            foreach (self::descriptorNamesForConfigKey($configKey) as $descriptor) {
                $descriptors[] = $descriptor;
            }
        }

        return array_values(array_unique($descriptors));
    }

    /**
     * @return list<string>
     */
    public static function descriptorNamesForConfigKey(string $configKey): array
    {
        return self::definitions()[$configKey]['descriptors'] ?? [];
    }

    /**
     * The descriptor a capability class reports from describe().
     *
     * CapabilityInterface allows one, so a config key publishing several names reports its
     * first here and the rest are added by CapabilityFilteringProfileContributor, which is
     * where this plugin already decides what its profile advertises per sales channel.
     */
    public static function primaryDescriptorName(string $configKey): ?string
    {
        return self::descriptorNamesForConfigKey($configKey)[0] ?? null;
    }

    public static function descriptor(string $configKey, ?string $descriptorName = null): CapabilityDescriptor
    {
        $definition = self::definitions()[$configKey] ?? null;
        if (null === $definition) {
            throw new \InvalidArgumentException(\sprintf('Unknown UCP capability config key "%s".', $configKey));
        }

        $name = $descriptorName ?? $definition['descriptors'][0];
        if (!\in_array($name, $definition['descriptors'], true)) {
            throw new \InvalidArgumentException(\sprintf('Descriptor "%s" does not belong to config key "%s".', $name, $configKey));
        }

        return new CapabilityDescriptor(
            $name,
            UcpProtocol::VERSION,
            $definition['specUrl'] ?? UcpProtocol::specificationUrl($definition['path']),
            $definition['schemaUrl'] ?? UcpProtocol::schemaUrl($definition['path']),
            $definition['extends'] ?? null,
        );
    }

    /**
     * Every descriptor a config key publishes.
     *
     * @return list<CapabilityDescriptor>
     */
    public static function descriptors(string $configKey): array
    {
        return array_map(
            static fn (string $name): CapabilityDescriptor => self::descriptor($configKey, $name),
            self::descriptorNamesForConfigKey($configKey),
        );
    }

    public static function isEnabled(?RuntimeConfiguration $runtimeConfiguration, string $descriptorName): bool
    {
        if (null === $runtimeConfiguration) {
            return false;
        }

        return \in_array($descriptorName, $runtimeConfiguration->enabledCapabilities, true);
    }
}
