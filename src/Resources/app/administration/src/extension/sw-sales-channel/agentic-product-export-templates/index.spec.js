/**
 * @sw-package discovery
 *
 * The template bodies are rendered and checked by the PHP suite; this pins how they are
 * registered, so the two providers can never swap names, formats or whitespace handling.
 */

const AGENTIC_COMMERCE_TYPE_ID = '5e29f9890c4d4d519a1c7f9d5c24b7c1';

describe('agentic-product-export-templates', () => {
    let registered;

    beforeEach(() => {
        const registerProductExportTemplate = jest.fn();
        global.Shopware = {
            Service: (name) => ({ exportTemplateService: { registerProductExportTemplate } })[name],
            Defaults: { agenticCommerceTypeId: AGENTIC_COMMERCE_TYPE_ID },
        };

        require('./index');

        registered = Object.fromEntries(registerProductExportTemplate.mock.calls.map(([template]) => [template.name, template]));
    });

    afterEach(() => {
        jest.resetModules();
        delete global.Shopware;
    });

    it('registers one OpenAI and one Google template for Agentic Commerce channels', () => {
        const shared = { salesChannelTypeId: AGENTIC_COMMERCE_TYPE_ID, encoding: 'UTF-8', generateByCronjob: false, interval: 86400 };

        expect(Object.keys(registered).sort()).toEqual(['google', 'open_ai']);
        expect(registered.open_ai).toMatchObject({ ...shared, providerName: 'open-ai', fileFormat: 'jsonl' });
        expect(registered.google).toMatchObject({ ...shared, providerName: 'google', fileFormat: 'xml' });
    });

    it('trims the OpenAI body and leaves its header and footer empty', () => {
        const body = require('./open-ai/body.json.twig.js').default;

        expect(registered.open_ai.bodyTemplate).toBe(body.trim());
        expect(registered.open_ai.headerTemplate).toBe('');
        expect(registered.open_ai.footerTemplate).toBe('');
    });

    it('keeps the Google body byte for byte and registers the RSS header and footer', () => {
        const header = require('./google/header.xml.twig.js').default;
        const body = require('./google/body.xml.twig.js').default;
        const footer = require('./google/footer.xml.twig.js').default;

        expect(registered.google.bodyTemplate).toBe(body);
        expect(registered.google.headerTemplate).toBe(header.trim());
        expect(registered.google.headerTemplate).toMatch(/^<\?xml[\s\S]*<rss /);
        expect(registered.google.footerTemplate).toBe(footer.trim());
    });
});