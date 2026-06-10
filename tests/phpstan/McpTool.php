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
