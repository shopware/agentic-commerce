<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\System\SystemConfig;

use Shopware\Core\System\SystemConfig\Util\ConfigReader;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;

/**
 * Shopware 6.5's config.xsd defines the `input-field` complex type with several
 * adjacent optional child elements that all share the same type
 * (`translatableString`). libxml2 >= 2.13 flags this as a non-deterministic
 * content model and rejects ALL XML files validated against that schema.
 *
 * On 6.5 this reader swaps in a bundled, permissive copy of the schema so config
 * validation keeps working. From 6.6 core's own schema is fixed and, crucially,
 * stays current (e.g. it adds `<subtitle>` in 6.7). There we must keep core's real
 * xsd — inherited via the parent's `$xsdFile` property default — because forcing
 * the frozen bundled copy would reject valid newer core config such as
 * basicInformation.xml.
 *
 * The service is registered unconditionally so the compiled DI container stays
 * deterministic (required for SaaS). The version decision therefore happens here
 * at runtime rather than at container-build time.
 *
 * @internal
 */
class CompatConfigReader extends ConfigReader
{
    public function __construct(?ShopwareVersionDetector $versionDetector = null)
    {
        $versionDetector ??= new ShopwareVersionDetector();

        // 6.5 only: swap in the bundled compat schema. On 6.6+ leave the inherited
        // $xsdFile default untouched so validation uses core's real, current schema.
        if ($versionDetector->needsSystemConfigXsdCompatPatch()) {
            $this->xsdFile = \dirname(__DIR__, 2).'/Resources/config/config.xsd';
        }
    }
}
