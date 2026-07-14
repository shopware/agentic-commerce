/**
 * @sw-package checkout
 *
 * AP2 mandate is a gated capability: it must never be part of the default
 * capability set, but a sales channel that explicitly enables it (because a
 * PSP plugin provides a mandate verifier) keeps it across normalization.
 */

import {
    defaultForm,
    normalizeConfig,
} from '../../../../src/Resources/app/administration/src/extension/sw-sales-channel/agentic-commerce/ucp-form-state.js';

describe('agentic-commerce/ucp-form-state', () => {
    it('keeps AP2 mandate as an optional disabled-by-default capability', () => {
        expect(defaultForm().enabledCapabilities).not.toContain('ap2_mandate');

        const normalized = normalizeConfig({
            enabledCapabilities: ['catalog', 'ap2_mandate'],
        });

        expect(normalized.enabledCapabilities).toContain('ap2_mandate');
        expect(normalized.enabledCapabilities).not.toContain('payment_tokenization');
    });
});
