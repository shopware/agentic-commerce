// Product-export template, kept as a JS module rather than an imported `.twig` file.
//
// It must reach `registerProductExportTemplate` byte-exact: the OpenAI feed is JSONL, so
// newlines are significant. Shopware's own twig loader collapses whitespace
// (`build/vite-plugins/twigjs-plugin`), which is why this used to be imported with vite's
// `?raw` suffix — but `?raw` is vite-only and esbuild (used for the release ZIP) cannot
// resolve it. A plain JS module is the one form every bundler treats identically.

export default `    </channel>
</rss>`;
