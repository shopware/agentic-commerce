<?php

declare(strict_types=1);

namespace Mcp\Capability\Attribute;

if (!class_exists(McpTool::class)) {
    #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
    final class McpTool
    {
        public function __construct(
            public readonly string $name,
            public readonly ?string $title = null,
            public readonly ?string $description = null,
        ) {
        }
    }
}

// MCP is optional on older Shopware lanes; mirror only the attribute API used here.
if (!class_exists(Schema::class)) {
    #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
    final class Schema
    {
        /**
         * @param array<string, mixed>|null $properties
         */
        public function __construct(public readonly ?array $properties = null)
        {
        }
    }
}
