import { expect, test } from '@fixtures/AcceptanceTest';

test.describe('Acceptance suite bootstrap @Setup', () => {
    test('resolves the Shopware instance version', ({ InstanceMeta }) => {
        expect(InstanceMeta.version).toMatch(/^\d+\.\d+/);
    });

    test('authenticates against the Admin API', async ({ AdminApiContext }) => {
        const response = await AdminApiContext.get('./_info/config');

        expect(response.ok(), await response.text()).toBeTruthy();
    });

    test('gives the worker its own sales channel on a path-prefixed domain', ({ DefaultSalesChannel }) => {
        expect(DefaultSalesChannel.salesChannel.id).toEqual(expect.any(String));
        expect(DefaultSalesChannel.url).toContain('/test-');
    });
});
