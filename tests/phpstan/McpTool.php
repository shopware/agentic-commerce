<?php

declare(strict_types=1);

namespace Mcp\Capability\Attribute;

if (!class_exists(McpTool::class)) {
    #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
    final readonly class McpTool
    {
        public function __construct(
            public string $name,
            public ?string $title = null,
            public ?string $description = null,
        ) {
        }
    }
}
