import { expect, request } from '@playwright/test';

export function laneConfig() {
    const lane = normalizeLane(process.env.SHOPWARE_LANE || process.env.SHOPWARE_REF || 'trunk');
    const baseUrl = stripTrailingSlash(process.env.BASE_URL || 'http://trunk.localhost:8088');

    return {
        baseUrl,
        lane,
        adminBuildMode: process.env.ADMIN_BUILD_MODE || process.env.SHOPWARE_ADMIN_BUILD_MODE || (lane === 'trunk' ? 'vite' : 'webpack'),
        username: process.env.SHOPWARE_ADMIN_USERNAME || 'admin',
        password: process.env.SHOPWARE_ADMIN_PASSWORD || 'shopware',
        expectedVersionLabel: expectedVersionLabel(lane),
    };
}

export async function createAdminApiContext(config = laneConfig()) {
    const authApi = await request.newContext({ baseURL: config.baseUrl });
    const response = await authApi.post('/api/oauth/token', {
        data: {
            grant_type: 'password',
            client_id: 'administration',
            scope: 'write',
            username: config.username,
            password: config.password,
        },
    });

    await expect(response, await response.text()).toBeOK();
    const payload = await response.json();
    await authApi.dispose();

    return request.newContext({
        baseURL: config.baseUrl,
        extraHTTPHeaders: {
            authorization: `Bearer ${payload.access_token}`,
            accept: 'application/json',
            'content-type': 'application/json',
        },
    });
}

export async function loginAdmin(page, config = laneConfig()) {
    const adminShell = page.locator('.sw-admin-menu, .sw-desktop, .sw-page').first();
    const usernameInput = page.locator('input[name="sw-field--username"], input[name="username"]').first();

    const response = await page.goto('/admin', { waitUntil: 'domcontentloaded' });
    if (response === null || !response.ok()) {
        const body = response === null ? 'No response received.' : (await response.text()).slice(0, 2_000);
        throw new Error(`Admin shell did not render. Status: ${response?.status() ?? 'n/a'} ${response?.statusText() ?? ''}\n${body}`);
    }

    await Promise.race([
        adminShell.waitFor({ state: 'visible', timeout: 120_000 }),
        usernameInput.waitFor({ state: 'visible', timeout: 120_000 }),
    ]);

    if (await adminShell.isVisible().catch(() => false)) {
        return;
    }

    await usernameInput.fill(config.username);
    await page.locator('input[name="sw-field--password"], input[name="password"], input[type="password"]').first().fill(config.password);
    await page.locator('button[type="submit"], .sw-login__login-action').first().click();
    await adminShell.waitFor({ state: 'visible', timeout: 120_000 });
}

export function attachBrowserFailureCollectors(page) {
    const consoleErrors = [];
    const networkFailures = [];

    page.on('console', (message) => {
        if (['error', 'warning'].includes(message.type()) && /ucp|swag-agentic-commerce|swagagenticcommerce|chunk|module/i.test(message.text())) {
            consoleErrors.push(`${message.type()}: ${message.text()}`);
        }
    });

    page.on('pageerror', (error) => {
        if (/ucp|swag-agentic-commerce|swagagenticcommerce|chunk|module/i.test(error.message)) {
            consoleErrors.push(`pageerror: ${error.message}`);
        }
    });

    page.on('requestfailed', (failedRequest) => {
        const errorText = failedRequest.failure()?.errorText || 'request failed';

        // Navigating between admin views cancels in-flight lazy chunks and fonts.
        // These aborts are a navigation artifact, not a real load failure.
        if (/ERR_ABORTED|ERR_CANCELED/i.test(errorText)) {
            return;
        }

        if (/\/admin|administration|swagagenticcommerce|swag-agentic-commerce|\.js|\.css/i.test(failedRequest.url())) {
            networkFailures.push(`${errorText}: ${failedRequest.method()} ${failedRequest.url()}`);
        }
    });

    return {
        assertClean() {
            expect([...consoleErrors, ...networkFailures], 'UCP-related browser console/network failures').toEqual([]);
        },
    };
}

export async function firstSalesChannel(adminApi) {
    const response = await adminApi.get('/api/_admin/ucp/sales-channels');
    await expect(response, await response.text()).toBeOK();
    const payload = await response.json();
    const salesChannel = payload.data?.[0];

    expect(salesChannel?.id, 'UCP admin API must return at least one sales channel').toEqual(expect.any(String));

    return { salesChannel, payload };
}

export async function withRestoredUcpConfig(adminApi, salesChannelId, callback) {
    const configResponse = await adminApi.get(`/api/_admin/ucp/sales-channels/${salesChannelId}/config`);
    await expect(configResponse, await configResponse.text()).toBeOK();
    const originalConfig = adminApiWritableConfig((await configResponse.json()).data);
    const createdKids = [];

    try {
        return await callback(originalConfig, createdKids);
    } finally {
        await Promise.all(createdKids.map((kid) => (
            adminApi.delete(`/api/_admin/ucp/sales-channels/${salesChannelId}/keys/${encodeURIComponent(kid)}`).catch(() => {})
        )));
        await adminApi.put(`/api/_admin/ucp/sales-channels/${salesChannelId}/config`, { data: originalConfig }).catch(() => {});
    }
}

function adminApiWritableConfig(config) {
    if (typeof config?.webhookUrlOverride !== 'string' || !config.webhookUrlOverride.startsWith('http://')) {
        return config;
    }

    return {
        ...config,
        webhookUrlOverride: null,
    };
}

export async function assertProfileTransports(profile, expectedTransports) {
    const profileRoot = profile.ucp || profile;
    const transports = [...new Set((profileRoot.services?.['dev.ucp.shopping'] || []).map((entry) => entry.transport))].sort();

    expect(transports).toEqual([...expectedTransports].sort());

    // Payment handlers are advertised whenever the sales channel is active. The
    // invoice handler is a delegated (non-tokenizing) handler backed by the
    // channel's default offline/invoice payment flow.
    const paymentHandlers = profileRoot.payment_handlers || {};
    expect(Object.keys(paymentHandlers)).toContain('com.shopware.invoice');
    expect(paymentHandlers['com.shopware.invoice'][0].config.tokenization).toBe(false);
}

export async function readPublicProfile(apiContext) {
    const response = await apiContext.get('/.well-known/ucp');
    await expect(response, await response.text()).toBeOK();

    return response.json();
}

function expectedVersionLabel(lane) {
    if (lane === '6.5') {
        return '6.5-dev';
    }

    if (lane === '6.6') {
        return '6.6-dev';
    }

    return '6.7-dev';
}

function normalizeLane(lane) {
    if (lane.startsWith('6.5')) {
        return '6.5';
    }

    if (lane.startsWith('6.6')) {
        return '6.6';
    }

    return 'trunk';
}

function stripTrailingSlash(value) {
    return value.replace(/\/+$/, '');
}
