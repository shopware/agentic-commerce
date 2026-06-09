import { expect, test } from '@playwright/test';
import {
    assertProfileTransports,
    createAdminApiContext,
    firstSalesChannel,
    laneConfig,
    withRestoredUcpConfig,
} from '../fixtures/shopware.js';

test.describe('UCP admin API', () => {
    test('returns list, detail, config, preview, and signing-key lifecycle', async () => {
        const config = laneConfig();
        const adminApi = await createAdminApiContext(config);
        const { salesChannel, payload } = await firstSalesChannel(adminApi);

        expect(payload.meta?.shopwareVersion).toEqual(expect.any(String));
        expect(salesChannel.ucp).toEqual(expect.objectContaining({
            active: expect.any(Boolean),
            enabledCapabilities: expect.any(Array),
            enabledTransports: expect.any(Array),
        }));

        const detailResponse = await adminApi.get(`/api/_admin/ucp/sales-channels/${salesChannel.id}`);
        await expect(detailResponse, await detailResponse.text()).toBeOK();
        expect((await detailResponse.json()).data.id).toBe(salesChannel.id);

        await withRestoredUcpConfig(adminApi, salesChannel.id, async (originalConfig, createdKids) => {
            const expectedTransports = payload.meta?.supportsStoreApiMcp === true
                ? ['rest', 'a2a', 'embedded', 'mcp']
                : ['rest', 'a2a', 'embedded'];
            const nextConfig = {
                ...originalConfig,
                active: true,
                enabledTransports: expectedTransports,
                signaturePolicy: 'strict',
            };

            const saveResponse = await adminApi.put(`/api/_admin/ucp/sales-channels/${salesChannel.id}/config`, { data: nextConfig });
            await expect(saveResponse, await saveResponse.text()).toBeOK();

            const configResponse = await adminApi.get(`/api/_admin/ucp/sales-channels/${salesChannel.id}/config`);
            await expect(configResponse, await configResponse.text()).toBeOK();
            expect((await configResponse.json()).data.enabledTransports).toEqual(expectedTransports);

            const previewResponse = await adminApi.get(`/api/_admin/ucp/sales-channels/${salesChannel.id}/profile-preview`);
            await expect(previewResponse, await previewResponse.text()).toBeOK();
            await assertProfileTransports((await previewResponse.json()).data, expectedTransports);

            const kid = `playwright-${Date.now()}`;
            createdKids.push(kid);

            const createKeyResponse = await adminApi.post(`/api/_admin/ucp/sales-channels/${salesChannel.id}/keys`, {
                data: { kid, algorithm: 'ES256' },
            });
            await expect(createKeyResponse, await createKeyResponse.text()).toBeOK();

            const keysResponse = await adminApi.get(`/api/_admin/ucp/sales-channels/${salesChannel.id}/keys`);
            await expect(keysResponse, await keysResponse.text()).toBeOK();
            expect((await keysResponse.json()).data.some((key) => key.kid === kid)).toBe(true);

            const retireResponse = await adminApi.post(`/api/_admin/ucp/sales-channels/${salesChannel.id}/keys/${encodeURIComponent(kid)}/retire`);
            await expect(retireResponse, await retireResponse.text()).toBeOK();

            const deleteResponse = await adminApi.delete(`/api/_admin/ucp/sales-channels/${salesChannel.id}/keys/${encodeURIComponent(kid)}`);
            await expect(deleteResponse, await deleteResponse.text()).toBeOK();
            createdKids.pop();
        });

        await adminApi.dispose();
    });
});
