/**
 * @sw-package discovery
 *
 * syncExportFileName must align the export filename extension and fileFormat with
 * the active provider's registered template, so the feed URL matches the format.
 */

// acme/csv is a provider that does not exist today — included to prove the logic
// is registry-driven, not hardcoded to specific providers or formats.
const TEMPLATE_REGISTRY = {
    openAi: { providerName: 'open-ai', fileFormat: 'jsonl' },
    google: { providerName: 'google', fileFormat: 'xml' },
    futureProvider: { providerName: 'acme', fileFormat: 'csv' },
};

// Stub for requiring the override: Utils/Classes only need to exist (read at
// import); syncExportFileName uses Service to resolve the provider's template.
global.Shopware = {
    Component: { override: jest.fn() },
    Service: (name) => (name === 'exportTemplateService'
        ? { getProductExportTemplateRegistry: () => TEMPLATE_REGISTRY }
        : {}),
    Utils: {},
    Classes: {},
};

const { swSalesChannelDetailOverride } = require('Resources/extension/sw-sales-channel/page/sw-sales-channel-detail');

const { syncExportFileName } = swSalesChannelDetailOverride.methods;

describe('sw-sales-channel-detail syncExportFileName', () => {
    it.each([
        ['open-ai', 'jsonl'],
        ['google', 'xml'],
        ['acme', 'csv'],
    ])('aligns the filename extension and fileFormat to the "%s" template (%s)', (provider, fileFormat) => {
        const context = { productExport: { provider, fileName: 'agentic-commerce-abc123.jsonl', fileFormat: 'jsonl' } };

        syncExportFileName.call(context);

        expect(context.productExport.fileName).toBe(`agentic-commerce-abc123.${fileFormat}`);
        expect(context.productExport.fileFormat).toBe(fileFormat);
    });

    it('appends the extension when the filename has none', () => {
        const context = { productExport: { provider: 'google', fileName: 'agentic-commerce-abc123', fileFormat: 'xml' } };

        syncExportFileName.call(context);

        expect(context.productExport.fileName).toBe('agentic-commerce-abc123.xml');
    });

    it('leaves an already-aligned export untouched', () => {
        const context = { productExport: { provider: 'google', fileName: 'feed.xml', fileFormat: 'xml' } };

        syncExportFileName.call(context);

        expect(context.productExport.fileName).toBe('feed.xml');
        expect(context.productExport.fileFormat).toBe('xml');
    });

    it.each([
        ['the provider is empty', { provider: '', fileName: 'feed.jsonl', fileFormat: 'jsonl' }],
        ['the filename is empty', { provider: 'google', fileName: '', fileFormat: 'jsonl' }],
        ['the provider has no registered template', { provider: 'unknown', fileName: 'feed.jsonl', fileFormat: 'jsonl' }],
    ])('makes no change when %s', (_label, productExport) => {
        const context = { productExport: { ...productExport } };

        syncExportFileName.call(context);

        expect(context.productExport.fileName).toBe(productExport.fileName);
        expect(context.productExport.fileFormat).toBe(productExport.fileFormat);
    });
});
