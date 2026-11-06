/**
 * @sw-package discovery
 */

global.Shopware = {
    Component: { override: jest.fn() },
    Utils: { object: { deepCopyObject: (obj) => JSON.parse(JSON.stringify(obj ?? {})) } },
    Defaults: { agenticCommerceTypeId: 'agentic-type-id', productComparisonTypeId: 'product-comparison-type-id' },
};

jest.mock(
    './sw-sales-channel-detail-base.html.twig',
    () => 'mock-template',
    { virtual: true },
);

const { swSalesChannelDetailBaseOverride } = require('./index');

const RETURN_POLICY_URL = 'SwagAgenticCommerce.openAiProductExport.returnPolicyUrl';
const onFieldUpdate = swSalesChannelDetailBaseOverride.methods.onAgenticCommerceExportFieldUpdate;

describe('sw-sales-channel-detail-base onAgenticCommerceExportFieldUpdate', () => {
    it('stores the entered value', () => {
        const configEntry = { values: {}, errors: {} };

        onFieldUpdate.call({}, configEntry, RETURN_POLICY_URL, 'https://shop.example/returns');

        expect(configEntry.values[RETURN_POLICY_URL]).toBe('https://shop.example/returns');
    });

    it('clears the error of the field the merchant fills in', () => {
        const configEntry = { values: {}, errors: { [RETURN_POLICY_URL]: { code: 'required' } } };

        onFieldUpdate.call({}, configEntry, RETURN_POLICY_URL, 'https://shop.example/returns');

        expect(configEntry.errors).toEqual({});
    });

    it('keeps the required error while the field is still empty', () => {
        const configEntry = { values: {}, errors: { [RETURN_POLICY_URL]: { code: 'required' } } };

        onFieldUpdate.call({}, configEntry, RETURN_POLICY_URL, '');

        expect(configEntry.errors).toEqual({ [RETURN_POLICY_URL]: { code: 'required' } });
    });

    it('stores a cleared value so a removed URL is saved as empty', () => {
        const configEntry = { values: { [RETURN_POLICY_URL]: 'https://shop.example/returns' }, errors: {} };

        onFieldUpdate.call({}, configEntry, RETURN_POLICY_URL, '');

        expect(configEntry.values[RETURN_POLICY_URL]).toBe('');
    });

    // Regression #309: Vue 2.7 on 6.5 does not track keys added to or deleted from an existing object.
    it('replaces the values and errors objects so Vue 2 redraws the field', () => {
        const values = {};
        const errors = { [RETURN_POLICY_URL]: { code: 'required' } };
        const configEntry = { values, errors };

        onFieldUpdate.call({}, configEntry, RETURN_POLICY_URL, 'https://shop.example/returns');

        expect(configEntry.values).not.toBe(values);
        expect(configEntry.errors).not.toBe(errors);
    });

    it('ignores native input events', () => {
        const configEntry = { values: {}, errors: {} };

        onFieldUpdate.call({}, configEntry, RETURN_POLICY_URL, new Event('input'));

        expect(configEntry.values).toEqual({});
    });
});
