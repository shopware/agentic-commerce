<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\DependencyInjection;

use Swag\AgenticCommerce\Ucp\Mcp\UcpMcpToolset;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Keeps the UCP tools on the first `tools/list` on Shopware releases that cannot pin a toolset at
 * connect time.
 *
 * The UCP tools sit in their own toolset ({@see UcpMcpToolset}), and `/ucp/mcp` pins it with
 * `?toolsets=ucp`. Releases before 6.7.15.0 have progressive disclosure on the Store API endpoint
 * but no connect-time pinning, so there a UCP agent would see only the discovery meta-tools. On
 * those releases this pass adds the UCP tools to the endpoint's default surface, which is what the
 * plugin did before. From 6.7.15.0 on it does nothing.
 *
 * Runs after core's McpToolDiscoveryCompilerPass (priority 20), which writes the parameter.
 *
 * @internal
 */
class AdvertiseUcpToolsWithoutToolsetPinningPass implements CompilerPassInterface
{
    public const ADVERTISED_TOOLS_PARAMETER = 'shopware.store_api_mcp.advertised_tools';

    private const STORE_API_TOOL_TAG = 'shopware.store_api_mcp.tool';

    private const TOOL_ATTRIBUTE = 'Mcp\\Capability\\Attribute\\McpTool';

    private const GROUP_ATTRIBUTE = 'Shopware\\Core\\Framework\\Mcp\\Attribute\\McpToolGroup';

    public function __construct(private readonly ?bool $coreSupportsConnectTimeToolsets = null)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        if (($this->coreSupportsConnectTimeToolsets ?? UcpMcpToolset::coreSupportsConnectTimeToolsets())
            || !$container->hasParameter(self::ADVERTISED_TOOLS_PARAMETER)) {
            return;
        }

        $advertised = $container->getParameter(self::ADVERTISED_TOOLS_PARAMETER);
        if (!\is_array($advertised)) {
            return;
        }

        foreach (array_keys($container->findTaggedServiceIds(self::STORE_API_TOOL_TAG)) as $serviceId) {
            $class = $container->getDefinition($serviceId)->getClass() ?? $serviceId;
            $name = $this->ucpToolName($class);

            if (null !== $name) {
                $advertised[] = $name;
            }
        }

        $container->setParameter(self::ADVERTISED_TOOLS_PARAMETER, array_values(array_unique($advertised)));
    }

    /**
     * The tool name when the class is a tool in the UCP toolset. Attribute arguments are read
     * without instantiating them, because the attribute classes only exist on newer releases.
     */
    private function ucpToolName(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);
        $group = null;
        foreach ($reflection->getAttributes(self::GROUP_ATTRIBUTE) as $attribute) {
            $group = $attribute->getArguments()[0] ?? $attribute->getArguments()['group'] ?? null;
        }

        if (UcpMcpToolset::NAME !== $group) {
            return null;
        }

        foreach ($reflection->getAttributes(self::TOOL_ATTRIBUTE) as $attribute) {
            $name = $attribute->getArguments()['name'] ?? $attribute->getArguments()[0] ?? null;

            return \is_string($name) ? $name : null;
        }

        return null;
    }
}
