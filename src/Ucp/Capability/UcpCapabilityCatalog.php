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

    public const DESCRIPTOR_CATALOG = 'dev.ucp.shopping.catalog';
    public const DESCRIPTOR_CART = 'dev.ucp.shopping.cart';
    public const DESCRIPTOR_DISCOUNT = 'dev.ucp.shopping.discount';
    public const DESCRIPTOR_CHECKOUT = 'dev.ucp.shopping.checkout';
    public const DESCRIPTOR_ORDER = 'dev.ucp.shopping.order';
    public const DESCRIPTOR_IDENTITY_LINKING = 'dev.ucp.common.identity_linking';
    public const DESCRIPTOR_PAYMENT_TOKENIZATION = 'dev.ucp.shopping.payment_tokenization';

    /**
     * @return array<string, array{descriptor: string, path: string, specUrl?: string, schemaUrl?: string, extends?: list<string>}>
     */
    private static function definitions(): array
    {
        return [
            self::CONFIG_CATALOG => ['descriptor' => self::DESCRIPTOR_CATALOG, 'path' => 'catalog'],
            self::CONFIG_CART => ['descriptor' => self::DESCRIPTOR_CART, 'path' => 'cart'],
            self::CONFIG_DISCOUNT => [
                'descriptor' => self::DESCRIPTOR_DISCOUNT,
                'path' => 'discount',
                'extends' => [
                    self::DESCRIPTOR_CART,
                    self::DESCRIPTOR_CHECKOUT,
                ],
            ],
            self::CONFIG_CHECKOUT => ['descriptor' => self::DESCRIPTOR_CHECKOUT, 'path' => 'checkout'],
            self::CONFIG_ORDER => ['descriptor' => self::DESCRIPTOR_ORDER, 'path' => 'order'],
            self::CONFIG_IDENTITY_LINKING => [
                'descriptor' => self::DESCRIPTOR_IDENTITY_LINKING,
                'path' => 'identity-linking',
                'specUrl' => 'https://ucp.dev/specification/identity-linking/',
                'schemaUrl' => 'https://ucp.dev/schemas/identity/oauth.json',
            ],
            self::CONFIG_PAYMENT_TOKENIZATION => [
                'descriptor' => self::DESCRIPTOR_PAYMENT_TOKENIZATION,
                'path' => 'payment-tokenization',
                'specUrl' => 'https://ucp.dev/specification/payment-token-exchange/',
                'schemaUrl' => 'https://ucp.dev/schemas/shopping/payment-tokenization.json',
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
            $descriptor = self::descriptorNameForConfigKey($configKey);
            if (null === $descriptor) {
                continue;
            }

            $descriptors[] = $descriptor;
        }

        return array_values(array_unique($descriptors));
    }

    public static function descriptorNameForConfigKey(string $configKey): ?string
    {
        return self::definitions()[$configKey]['descriptor'] ?? null;
    }

    public static function descriptor(string $configKey): CapabilityDescriptor
    {
        $definition = self::definitions()[$configKey] ?? null;
        if (null === $definition) {
            throw new \InvalidArgumentException(\sprintf('Unknown UCP capability config key "%s".', $configKey));
        }

        return new CapabilityDescriptor(
            $definition['descriptor'],
            UcpProtocol::VERSION,
            $definition['specUrl'] ?? UcpProtocol::specificationUrl($definition['path']),
            $definition['schemaUrl'] ?? UcpProtocol::schemaUrl($definition['path']),
            $definition['extends'] ?? null,
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
