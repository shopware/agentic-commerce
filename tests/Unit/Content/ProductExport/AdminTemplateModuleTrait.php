<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport;

/**
 * Reads a product-export Twig template out of the administration module that ships it.
 *
 * The templates are authored as `.twig.js` modules exporting a template literal rather
 * than as bare `.twig` files. They have to reach `registerProductExportTemplate` byte for
 * byte — the OpenAI feed is JSONL, so newlines are significant — and Shopware's own Twig
 * loader collapses whitespace on any imported `.twig`. A plain JS module is the only form
 * every admin bundler (esbuild for the release zip, Vite/webpack in a lane) treats
 * identically.
 *
 * Unwrapping here means these tests render exactly the bytes the plugin ships, which is
 * stricter than reading a separate `.twig` file that nothing would consume.
 *
 * @internal
 */
trait AdminTemplateModuleTrait
{
    /**
     * @param string $path absolute path to a `*.twig.js` administration template module
     */
    private function readTemplateModule(string $path): string
    {
        // Locals rather than trait constants: constants in traits need PHP 8.2 and the
        // plugin's floor is 8.1 (see the php-quality (php-8.1) lane).
        $prefix = 'export default `';
        $suffix = "`;\n";

        $module = file_get_contents($path);

        static::assertIsString($module, \sprintf('Template module is not readable: %s', $path));

        $start = mb_strpos($module, $prefix);

        static::assertIsInt($start, \sprintf('Template module has no "%s" export: %s', $prefix, $path));

        $start += mb_strlen($prefix);
        $end = mb_strrpos($module, $suffix);

        static::assertIsInt($end, \sprintf('Template module does not end in "%s": %s', trim($suffix), $path));
        static::assertGreaterThan($start, $end, \sprintf('Template module is empty: %s', $path));

        // The templates contain no backtick, `${` or backslash, so the literal needs no
        // unescaping. Assert that rather than trusting it: a future edit that introduces
        // one must update the generator and this reader together.
        $template = mb_substr($module, $start, $end - $start);

        static::assertDoesNotMatchRegularExpression(
            '/`|\$\{|\\\\/',
            $template,
            \sprintf('Template module contains characters that a template literal escapes: %s', $path),
        );

        return $template;
    }
}
