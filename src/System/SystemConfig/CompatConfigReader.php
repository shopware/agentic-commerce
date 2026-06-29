<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\System\SystemConfig;

use Shopware\Core\System\SystemConfig\Util\ConfigReader;

/**
 * Shopware 6.5's config.xsd defines the `input-field` complex type with several
 * adjacent optional child elements that all share the same type
 * (`translatableString`). libxml2 >= 2.13 flags this as a non-deterministic
 * content model and rejects ALL XML files validated against that schema.
 *
 * This subclass points to a bundled copy of the 6.6 schema which replaced the
 * named child elements with a single `xs:any processContents="lax"`. The 6.6
 * schema is strictly more permissive — every document valid under 6.5's schema
 * is also valid under the 6.6 schema.
 *
 * @internal
 */
class CompatConfigReader extends ConfigReader
{
    public function __construct()
    {
        $this->xsdFile = \dirname(__DIR__, 2).'/Resources/config/config.xsd';
    }
}
