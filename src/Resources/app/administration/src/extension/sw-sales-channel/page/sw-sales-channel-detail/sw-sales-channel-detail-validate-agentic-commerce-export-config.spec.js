/**
 * @sw-package discovery
 */

global.Shopware = {
    Component: { override: jest.fn() },
    Utils: {
        object: { deepCopyObject: (obj) => JSON.parse(JSON.stringify(obj ?? {})) },
    },
    Classes: {
        ShopwareError: class ShopwareError {
            constructor(payload) {
                this.code = payload.code;
            }
        },
    },
    Context: { api: {} },
    Defaults: { agenticCommerceTypeId: 'agentic-type-id' },
};

jest.mock(
    './sw-sales-channel-detail.html.twig',
    () => 'mock-template',
    { virtual: true },
);

const { swSalesChannelDetailOverride } = require('./index');

const RETURN_POLICY_URL = 'SwagAgenticCommerce.openAiProductExport.returnPolicyUrl';
const REQUIRED_ERROR_CODE = 'c1051bb4-d103-4f74-8988-acbcafc7fdc3';

function buildOpenAiContext(values) {
    return {
        productExport: { provider: 'open-ai' },
        defaultAgenticCommerceExportConfig: [{ provider: 'open-ai' }],
        agenticCommerceExportConfig: [
            {
                provider: 'open-ai',
                isLoaded: true,
                elements: [{ name: RETURN_POLICY_URL, config: { required: true } }],
                values,
                errors: {},
            },
        ],
    };
}

describe('sw-sales-channel-detail validateAgenticCommerceExportConfig', () => {
    it('accepts an export config with every required setting filled in', () => {
        const ctx = buildOpenAiContext({ [RETURN_POLICY_URL]: 'https://shop.example/returns' });

        const isValid = swSalesChannelDetailOverride.methods.validateAgenticCommerceExportConfig.call(ctx);

        expect(isValid).toBe(true);
        expect(ctx.agenticCommerceExportConfig[0].errors).toEqual({});
    });

    it('marks an empty required setting with the required-field error', () => {
        const ctx = buildOpenAiContext({});

        const isValid = swSalesChannelDetailOverride.methods.validateAgenticCommerceExportConfig.call(ctx);

        expect(isValid).toBe(false);
        expect(ctx.agenticCommerceExportConfig[0].errors[RETURN_POLICY_URL].code).toBe(REQUIRED_ERROR_CODE);
    });

    // Regression #309: Vue 2.7 on 6.5 only redraws the field when `errors` is replaced, not extended.
    it('replaces the errors object so Vue 2 redraws the marked field', () => {
        const ctx = buildOpenAiContext({});
        const errorsBeforeValidation = ctx.agenticCommerceExportConfig[0].errors;

        swSalesChannelDetailOverride.methods.validateAgenticCommerceExportConfig.call(ctx);

        expect(ctx.agenticCommerceExportConfig[0].errors).not.toBe(errorsBeforeValidation);
    });
});
