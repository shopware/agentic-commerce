<?php

declare(strict_types=1);

// MCP is optional on older Shopware lanes, where mcp/sdk is not installed. The UCP tools return
// a CallToolResult, so mirror only the part of its API used here; the tools never run there.

namespace Mcp\Schema\Content;

if (!class_exists(TextContent::class)) {
    final class TextContent
    {
        public function __construct(public mixed $text)
        {
        }
    }
}

namespace Mcp\Schema\Result;

use Mcp\Schema\Content\TextContent;

if (!class_exists(CallToolResult::class)) {
    final class CallToolResult
    {
        /**
         * @param list<TextContent>         $content
         * @param array<string, mixed>|null $structuredContent
         * @param array<string, mixed>|null $meta
         */
        public function __construct(
            public readonly array $content,
            public readonly bool $isError = false,
            public readonly ?array $structuredContent = null,
            public readonly ?array $meta = null,
        ) {
        }
    }
}
