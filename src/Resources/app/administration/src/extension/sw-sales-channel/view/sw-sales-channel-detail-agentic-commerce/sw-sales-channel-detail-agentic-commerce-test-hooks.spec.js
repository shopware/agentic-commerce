/**
 * @sw-package discovery
 *
 * The acceptance suite (tests/acceptance, @UcpAdmin) drives the tab through these
 * `data-testid` hooks. Only one template variant renders per Shopware lane, so both
 * must carry every hook. On 6.5 the `sw-*` form fields set `inheritAttrs: false` and
 * drop attributes, so there a hook only reaches the DOM on a plain element.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const HOOKS = [
    'data-testid="ac-ucp"',
    'data-testid="ac-ucp__unsaved-banner"',
    'data-testid="ac-ucp__expose-switch"',
    ':data-testid="`ac-ucp__capability--${capability.value}`"',
    ':data-testid="`ac-ucp__transport--${transport.value}`"',
    'data-testid="ac-ucp__preview"',
];

/** Components that keep attributes on their root on 6.5, besides plain HTML elements. */
const FORWARDING_COMPONENTS = ['sw-alert'];

const readTemplate = (path) => readFileSync(resolve(__dirname, path), 'utf8');

/** The name of the tag whose attribute list holds `hook`. */
function tagCarrying(template, hook) {
    const tagStart = template.lastIndexOf('<', template.indexOf(hook));

    return template.slice(tagStart + 1).match(/^[\w-]+/)[0];
}

const isPlainOrForwarding = (tag) => !tag.includes('-') || FORWARDING_COMPONENTS.includes(tag);

describe('sw-sales-channel-detail-agentic-commerce test hooks', () => {
    it.each([
        ['sw-sales-channel-detail-agentic-commerce.mt.html.twig'],
        ['sw-sales-channel-detail-agentic-commerce.sw.html.twig'],
    ])('%s carries every hook the acceptance suite uses', (templateFile) => {
        const template = readTemplate(templateFile);

        HOOKS.forEach((hook) => {
            expect(template).toContain(hook);
        });
    });

    it.each(HOOKS)('the 6.5 template puts %s on an element that keeps it', (hook) => {
        const tag = tagCarrying(readTemplate('sw-sales-channel-detail-agentic-commerce.sw.html.twig'), hook);

        expect(isPlainOrForwarding(tag)).toBe(true);
    });

    it('the template select hook sits on an element that keeps it on 6.5', () => {
        const template = readTemplate('../sw-sales-channel-detail-base/sw-sales-channel-detail-base.html.twig');

        expect(isPlainOrForwarding(tagCarrying(template, 'data-testid="ac-export__template-select"'))).toBe(true);
    });
});