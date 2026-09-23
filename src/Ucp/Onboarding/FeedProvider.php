<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * The providers an Agentic Commerce feed channel can be created for. The values
 * are the `product_export.provider` keys the Admin templates register.
 *
 * @internal
 */
#[Package('framework')]
enum FeedProvider: string
{
    case OpenAi = 'open-ai';
    case Google = 'google';

    public function fileFormat(): string
    {
        return match ($this) {
            self::OpenAi => SwagAgenticCommerce::FILE_FORMAT_JSONL,
            self::Google => 'xml',
        };
    }

    public function fileExtension(): string
    {
        return $this->fileFormat();
    }

    public function channelNameSuffix(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::Google => 'Google Shopping',
        };
    }

    public function templateDirectory(): string
    {
        return $this->value;
    }

    public function configDomain(): string
    {
        return match ($this) {
            self::OpenAi => SwagAgenticCommerce::OPEN_AI_PRODUCT_EXPORT_CONFIG_DOMAIN,
            self::Google => SwagAgenticCommerce::GOOGLE_PRODUCT_EXPORT_CONFIG_DOMAIN,
        };
    }
}
