import {
    UCP_SUB_TABS,
    DEFAULT_SUB_TAB,
    buildSubTabItems,
    resolveActiveSubTab,
} from '../../../../../src/extension/sw-sales-channel/agentic-commerce/ucp-sub-tabs';

describe('agentic-commerce/ucp-sub-tabs', () => {
    it('defines exactly three sub-tabs with Exposure first', () => {
        expect(UCP_SUB_TABS).toHaveLength(3);
        expect(DEFAULT_SUB_TAB).toBe('exposure');
        expect(UCP_SUB_TABS[0].name).toBe('exposure');
    });

    describe('buildSubTabItems', () => {
        it('enables all tabs when UCP is active', () => {
            const items = buildSubTabItems((key) => key, { active: true });
            expect(items.every((item) => item.disabled === false)).toBe(true);
        });

        it('disables everything but Exposure when UCP is off', () => {
            const items = buildSubTabItems((key) => key, { active: false });
            expect(items.find((i) => i.name === 'exposure').disabled).toBe(false);
            expect(items.find((i) => i.name === 'capabilitiesSecurity').disabled).toBe(true);
            expect(items.find((i) => i.name === 'developerAdvanced').disabled).toBe(true);
        });

        it('translates labels through the provided function', () => {
            const items = buildSubTabItems((key) => `T:${key}`);
            expect(items[0].label.startsWith('T:')).toBe(true);
        });
    });

    describe('resolveActiveSubTab', () => {
        it('keeps a valid, enabled tab', () => {
            expect(resolveActiveSubTab('capabilitiesSecurity', { active: true })).toBe('capabilitiesSecurity');
        });
        it('falls back to Exposure for a disabled tab when UCP is off', () => {
            expect(resolveActiveSubTab('developerAdvanced', { active: false })).toBe('exposure');
        });
        it('falls back to default for an unknown tab', () => {
            expect(resolveActiveSubTab('bogus')).toBe('exposure');
        });
    });
});
