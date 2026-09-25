/**
 * @sw-package discovery
 *
 * The Exposure sub-tab links the capability/transport checkboxes to the user
 * documentation. The link is snippet-driven so each admin locale points at its
 * own docs page; both the Meteor (6.6+) and the legacy (6.5) template must
 * render it, and both locales must resolve to the UCP section of the
 * Agentic Commerce user docs.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const VIEW_DIR = resolve(
    __dirname,
    '../../../../../src/extension/sw-sales-channel/view/sw-sales-channel-detail-agentic-commerce',
);
const SNIPPET_DIR = resolve(__dirname, '../../../../../src/extension/sw-sales-channel/snippet');

const DOCS_URL_KEY = 'sw-sales-channel.detail.agenticCommerce.ucp.docsUrl';
const DOCS_LABEL_KEY = 'sw-sales-channel.detail.agenticCommerce.ucp.docsLinkLabel';

function readSnippets(locale) {
    return JSON.parse(readFileSync(resolve(SNIPPET_DIR, `${locale}.json`), 'utf8'));
}

describe('sw-sales-channel-detail-agentic-commerce docs link', () => {
    it.each([
        ['sw-sales-channel-detail-agentic-commerce.mt.html.twig'],
        ['sw-sales-channel-detail-agentic-commerce.sw.html.twig'],
    ])('%s renders the docs link below the option grid', (templateFile) => {
        const template = readFileSync(resolve(VIEW_DIR, templateFile), 'utf8');

        const optionGridIndex = template.indexOf('sw-sales-channel-detail-agentic-commerce__option-grid');
        const docsLinkIndex = template.indexOf('sw-sales-channel-detail-agentic-commerce__docs-link');

        expect(optionGridIndex).toBeGreaterThan(-1);
        expect(docsLinkIndex).toBeGreaterThan(optionGridIndex);
        expect(template).toContain(`:href="$t('${DOCS_URL_KEY}')"`);
        expect(template).toContain(`$t('${DOCS_LABEL_KEY}')`);
    });

    it.each([
        ['en', 'https://docs.shopware.com/en/shopware-6-en/extensions/agentic-commerce#ucp-universal-commerce-protocol'],
        ['de', 'https://docs.shopware.com/de/shopware-6-de/erweiterungen/agentic-commerce#ucp-universal-commerce-protocol'],
    ])('the %s snippets point at the UCP section of the user docs', (locale, expectedUrl) => {
        const ucp = readSnippets(locale)['sw-sales-channel'].detail.agenticCommerce.ucp;

        expect(ucp.docsUrl).toBe(expectedUrl);
        expect(ucp.docsLinkLabel.trim()).not.toBe('');
    });
});
