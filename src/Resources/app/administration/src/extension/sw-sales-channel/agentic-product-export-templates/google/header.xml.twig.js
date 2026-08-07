// Product-export template, kept as a JS module rather than an imported `.twig` file.
//
// It must reach `registerProductExportTemplate` byte-exact: the OpenAI feed is JSONL, so
// newlines are significant. Shopware's own twig loader collapses whitespace
// (`build/vite-plugins/twigjs-plugin`), which is why this used to be imported with vite's
// `?raw` suffix — but `?raw` is vite-only and esbuild (used for the release ZIP) cannot
// resolve it. A plain JS module is the one form every bundler treats identically.

export default `<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <atom:link href="{{ productExport.salesChannelDomain.url|escape }}/store-api/product-export/{{ productExport.accessKey|escape }}/{{ productExport.fileName|escape }}" rel="self" type="application/rss+xml" />
        <title>{{ context.salesChannel.name|escape }}</title>
        <description>{{ context.salesChannel.name|escape }}</description>
        <link>{{ productExport.salesChannelDomain.url|escape }}</link>
        <language>{{ productExport.salesChannelDomain.language.locale.code|escape }}</language>`;
