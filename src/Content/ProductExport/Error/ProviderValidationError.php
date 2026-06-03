<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Error;

use Shopware\Core\Content\ProductExport\Error\Error;
use Shopware\Core\Content\ProductExport\Error\ErrorMessage;

class ProviderValidationError extends Error
{
    /**
     * @var list<ErrorMessage>
     */
    protected array $errorMessages;

    public function __construct(
        protected string $id,
        protected string $provider,
        protected string $field,
        protected string $error,
        protected ?int $errorLine = null,
    ) {
        $message = new ErrorMessage();
        /*
         * `column` is an uninitialized typed property on 6.7.0.x/6.7.1.x core;
         * it gained a `= null` default only in 6.7.2.x. Set it explicitly here
         * so any later read (test or runtime) does not trip "must not be
         * accessed before initialization" on the older releases.
         */
        $message->assign([
            'message' => $error,
            'line' => $this->errorLine,
            'column' => null,
        ]);

        $this->errorMessages = [$message];
        $this->message = 'The export did not satisfy the provider requirements';

        parent::__construct($this->message);
    }

    public function getId(): string
    {
        return $this->getMessageKey().$this->id.$this->provider.$this->field.($this->errorLine ?? 'global');
    }

    public function getMessageKey(): string
    {
        return 'provider-validation-failed';
    }

    /**
     * @return array<string, int|string|null>
     */
    public function getParameters(): array
    {
        return [
            'provider' => $this->provider,
            'field' => $this->field,
            'error' => $this->error,
            'line' => $this->errorLine,
        ];
    }

    /**
     * @return list<ErrorMessage>
     */
    public function getErrorMessages(): array
    {
        return $this->errorMessages;
    }
}
