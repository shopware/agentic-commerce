<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

final class UcpOAuthSchema
{
    public const CODE_TABLE = 'swag_agentic_commerce_ucp_oauth_code';
    public const ACCESS_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_access_token';
    public const REFRESH_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_refresh_token';

    private function __construct()
    {
    }
}
