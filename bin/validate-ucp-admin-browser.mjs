#!/usr/bin/env node
import { chromium, request as playwrightRequest } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const args = parseArgs(process.argv.slice(2));
const baseUrl = stripTrailingSlash(args.baseUrl || process.env.BASE_URL || '');
const username = args.username || process.env.SHOPWARE_ADMIN_USERNAME || 'admin';
const password = args.password || process.env.SHOPWARE_ADMIN_PASSWORD || 'shopware';
const screenshotDir = args.screenshotDir || process.env.UCP_ADMIN_SCREENSHOT_DIR || 'var/qa-screenshots';
const lane = args.lane || process.env.SHOPWARE_REF || 'unknown';
const headless = args.headed !== '1' && process.env.UCP_ADMIN_HEADED !== '1';
const adminBootTimeout = Number(process.env.UCP_ADMIN_BOOT_TIMEOUT || 180000);
const routeTimeout = Number(process.env.UCP_ADMIN_ROUTE_TIMEOUT || 120000);

if (!baseUrl) {
  fail('Missing --base-url or BASE_URL.');
}

await mkdir(screenshotDir, { recursive: true });

const browser = await chromium.launch({ headless });
const context = await browser.newContext({
  baseURL: baseUrl,
  viewport: { width: 1440, height: 1200 },
});
const page = await context.newPage();
const consoleErrors = [];
const networkFailures = [];

page.on('console', (message) => {
  if (['error', 'warning'].includes(message.type()) && /ucp|swag-agentic-commerce|chunk|module/i.test(message.text())) {
    consoleErrors.push(`${message.type()}: ${message.text()}`);
  }
});

page.on('pageerror', (error) => {
  if (/ucp|swag-agentic-commerce|chunk|module/i.test(error.message)) {
    consoleErrors.push(`pageerror: ${error.message}`);
  }
});

page.on('requestfailed', (request) => {
  if (/\/admin|administration|swagagenticcommerce|swag-agentic-commerce|\.js|\.css/i.test(request.url())) {
    networkFailures.push(`${request.failure()?.errorText || 'request failed'}: ${request.method()} ${request.url()}`);
  }
});

let originalConfig = null;
let createdKid = null;

try {
  const api = await createAdminApiContext(baseUrl, username, password);

  await login(page, baseUrl, username, password);

  await page.goto(`${baseUrl}/admin#/sw/settings/index`);
  await page.locator('.sw-settings-index').first().waitFor({ state: 'visible', timeout: routeTimeout });
  await assertUcpSettingsItem(page);
  await page.screenshot({ path: path.join(screenshotDir, `admin-ucp-${safeName(lane)}-settings.png`), fullPage: true });

  await page.goto(`${baseUrl}/admin#/sw/settings/ucp/index`);
  await page.locator('.sw-settings-ucp-index').first().waitFor({ state: 'visible', timeout: routeTimeout });
  await expectText(page, 'UCP');
  await expectText(page, 'Sales channel');
  await expectText(page, 'Active sales channels');
  await page.screenshot({ path: path.join(screenshotDir, `admin-ucp-${safeName(lane)}-index.png`), fullPage: true });

  const salesChannelsResponse = await api.get('/api/_admin/ucp/sales-channels');
  await assertOk(salesChannelsResponse, 'Unable to fetch UCP sales channels through the admin API.');
  const salesChannelsPayload = await salesChannelsResponse.json();
  const salesChannel = salesChannelsPayload.data?.[0];
  const inactiveSalesChannels = (salesChannelsPayload.data || []).filter((entry) => entry?.ucp?.active !== true);

  if (inactiveSalesChannels.length > 0) {
    await expectText(page, 'Inactive sales channels');
    await expectText(page, 'Activate');
  }

  if (!salesChannel?.id) {
    fail('No sales channel returned by the UCP admin API.');
  }

  await page.goto(`${baseUrl}/admin#/sw/sales/channel/detail/${salesChannel.id}/base`);
  await page.locator('.sw-sales-channel-detail').first().waitFor({ state: 'visible', timeout: routeTimeout });
  await expectText(page, 'Agent access');
  await expectText(page, 'Configure UCP');
  await page.screenshot({ path: path.join(screenshotDir, `admin-ucp-${safeName(lane)}-sales-channel.png`), fullPage: true });

  const expectedTransports = salesChannelsPayload.meta?.supportsStoreApiMcp === true
    ? ['rest', 'a2a', 'embedded', 'mcp']
    : ['rest', 'a2a', 'embedded'];

  const configResponse = await api.get(`/api/_admin/ucp/sales-channels/${salesChannel.id}/config`);
  await assertOk(configResponse, 'Unable to fetch original UCP config.');
  originalConfig = (await configResponse.json()).data;

  const qaConfig = {
    ...originalConfig,
    active: true,
    enabledTransports: expectedTransports,
    remoteProfileAllowlist: ['agent-platform.example'],
    agentAllowlist: ['agent.example'],
    embeddedAllowedOrigins: ['https://assistant.example'],
    embeddedFrameAncestors: ['https://assistant.example'],
    signaturePolicy: 'strict',
  };

  const saveResponse = await api.put(`/api/_admin/ucp/sales-channels/${salesChannel.id}/config`, { data: qaConfig });
  await assertOk(saveResponse, 'Unable to save QA UCP config through the admin API.');

  await page.goto(`${baseUrl}/admin#/sw/settings/ucp/detail/${salesChannel.id}`);
  await page.locator('.sw-settings-ucp-detail').first().waitFor({ state: 'visible', timeout: routeTimeout });
  await expectText(page, salesChannel.name);
  await expectText(page, 'REST');
  await expectText(page, 'A2A');
  await expectText(page, 'Embedded');
  await expectText(page, 'MCP');
  await page.screenshot({ path: path.join(screenshotDir, `admin-ucp-${safeName(lane)}-detail.png`), fullPage: true });

  const previewResponse = await api.get(`/api/_admin/ucp/sales-channels/${salesChannel.id}/profile-preview`);
  await assertOk(previewResponse, 'Unable to fetch UCP profile preview.');
  const preview = (await previewResponse.json()).data;
  const previewProfile = preview.ucp || preview;
  const previewTransports = [...new Set((previewProfile.services?.['dev.ucp.shopping'] || []).map((entry) => entry.transport))].sort();
  const previewPaymentHandlers = previewProfile.payment_handlers || {};

  assertEqualArrays(previewTransports, [...expectedTransports].sort(), 'Profile preview transports do not match the lane-aware admin expectation.');
  if (Object.keys(previewPaymentHandlers).length > 0) {
    fail('Profile preview must not advertise payment handlers.');
  }

  createdKid = `admin-qa-${Date.now()}`;
  const createKeyResponse = await api.post(`/api/_admin/ucp/sales-channels/${salesChannel.id}/keys`, {
    data: { kid: createdKid, algorithm: 'ES256' },
  });
  await assertOk(createKeyResponse, 'Unable to create signing key through the admin API.');

  const keysResponse = await api.get(`/api/_admin/ucp/sales-channels/${salesChannel.id}/keys`);
  await assertOk(keysResponse, 'Unable to list signing keys through the admin API.');
  const keys = (await keysResponse.json()).data || [];
  if (!keys.some((key) => key.kid === createdKid)) {
    fail(`Created signing key ${createdKid} was not returned by the admin key list.`);
  }

  const retireKeyResponse = await api.post(`/api/_admin/ucp/sales-channels/${salesChannel.id}/keys/${encodeURIComponent(createdKid)}/retire`);
  await assertOk(retireKeyResponse, 'Unable to retire signing key through the admin API.');

  const deleteKeyResponse = await api.delete(`/api/_admin/ucp/sales-channels/${salesChannel.id}/keys/${encodeURIComponent(createdKid)}`);
  await assertOk(deleteKeyResponse, 'Unable to delete signing key through the admin API.');
  createdKid = null;

  if (consoleErrors.length > 0) {
    fail(`UCP admin console errors detected:\n${consoleErrors.join('\n')}`);
  }

  console.log(`UCP admin browser validation passed for ${baseUrl} (${lane}).`);
} finally {
  try {
    if (originalConfig !== null) {
      const api = await createAdminApiContext(baseUrl, username, password);
      const salesChannelsResponse = await api.get('/api/_admin/ucp/sales-channels');
      if (salesChannelsResponse.ok()) {
        const salesChannel = (await salesChannelsResponse.json()).data?.[0];
        if (salesChannel?.id) {
          if (createdKid !== null) {
            await api.delete(`/api/_admin/ucp/sales-channels/${salesChannel.id}/keys/${encodeURIComponent(createdKid)}`).catch(() => {});
          }
          await api.put(`/api/_admin/ucp/sales-channels/${salesChannel.id}/config`, { data: originalConfig }).catch(() => {});
        }
      }
      await api.dispose();
    }
  } finally {
    await context.close();
    await browser.close();
  }
}

async function login(page, baseUrl, user, pass) {
  const usernameInput = page.locator('input[name="sw-field--username"], input[name="username"]').first();
  const adminShell = page.locator('.sw-admin-menu, .sw-desktop, .sw-page').first();
  let lastError = null;

  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      await page.goto(`${baseUrl}/admin`, { waitUntil: 'domcontentloaded', timeout: adminBootTimeout });
      await Promise.any([
        usernameInput.waitFor({ state: 'visible', timeout: adminBootTimeout }),
        adminShell.waitFor({ state: 'visible', timeout: adminBootTimeout }),
      ]);

      lastError = null;
      break;
    } catch (error) {
      lastError = error;
      await page.screenshot({ path: path.join(screenshotDir, `admin-login-${safeName(lane)}-attempt-${attempt}.png`), fullPage: true }).catch(() => {});
      await page.reload({ waitUntil: 'domcontentloaded', timeout: adminBootTimeout }).catch(() => {});
    }
  }

  if (lastError !== null) {
    const title = await page.title().catch(() => '');
    const bodyText = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
    const diagnostics = [
      `Admin login shell did not become visible for ${baseUrl}/admin.`,
      `Current URL: ${page.url()}`,
      `Title: ${title}`,
      `Body: ${bodyText.replace(/\s+/g, ' ').slice(0, 1000)}`,
      ...consoleErrors.slice(-20),
      ...networkFailures.slice(-20),
    ].filter(Boolean);

    fail(`${diagnostics.join('\n')}\n${lastError.stack || lastError.message || lastError}`);
  }

  if (await adminShell.isVisible().catch(() => false)) {
    return;
  }

  await usernameInput.fill(user);
  await page.locator('input[name="sw-field--password"], input[name="password"], input[type="password"]').first().fill(pass);
  await page.locator('button[type="submit"], .sw-login__login-action').first().click();
  await Promise.race([
    adminShell.waitFor({ state: 'visible', timeout: adminBootTimeout }),
    page.waitForLoadState('networkidle', { timeout: adminBootTimeout }),
  ]).catch(() => {});
}

async function createAdminApiContext(baseUrl, user, pass) {
  const api = await playwrightRequest.newContext({ baseURL: baseUrl });
  const response = await api.post('/api/oauth/token', {
    data: {
      grant_type: 'password',
      client_id: 'administration',
      scope: 'write',
      username: user,
      password: pass,
    },
  });

  await assertOk(response, 'Unable to authenticate against the Shopware admin API.');
  const payload = await response.json();
  await api.dispose();

  return playwrightRequest.newContext({
    baseURL: baseUrl,
    extraHTTPHeaders: {
      authorization: `Bearer ${payload.access_token}`,
      accept: 'application/json',
      'content-type': 'application/json',
    },
  });
}

async function expectText(page, text) {
  await page.getByText(text, { exact: false }).first().waitFor({ state: 'visible', timeout: 30000 });
}

async function assertUcpSettingsItem(page) {
  const state = await page.evaluate(() => {
    const getSettingsGroups = () => {
      try {
        const groups = Shopware?.Store?.get?.('settingsItems')?.settingsGroups;
        if (groups) {
          return groups;
        }
      } catch {
        // Shopware 6.5/6.6 can expose settings items through the legacy state
        // store while the newer Store API is unavailable for browser evals.
      }

      return Shopware?.State?.get?.('settingsItems')?.settingsGroups ?? {};
    };

    const registry = Shopware?.Module?.getModuleRegistry?.();
    const moduleExists = Boolean(registry?.get?.('sw-settings-ucp'));
    const settingsGroups = getSettingsGroups();
    const groups = Object.fromEntries(
      Object.entries(settingsGroups).map(([group, items]) => [
        group,
        (items || []).map((item) => ({
          name: item.name ?? null,
          to: typeof item.to === 'string' ? item.to : item.to?.name ?? null,
          label: typeof item.label === 'string' ? item.label : item.label?.label ?? null,
        })),
      ]),
    );

    return {
      moduleExists,
      groups,
      ucpItems: Object.entries(groups).flatMap(([group, items]) => (
        items
          .filter((item) => item.name === 'sw-settings-ucp' || item.to === 'sw.settings.ucp.index' || /ucp/i.test(item.label || ''))
          .map((item) => ({ group, ...item }))
      )),
    };
  });

  if (!state.moduleExists) {
    fail('The sw-settings-ucp administration module was not registered.');
  }

  if (state.ucpItems.length === 0) {
    fail(`The UCP settings item was not registered in the settings item store.\n${JSON.stringify(state.groups, null, 2)}`);
  }

  const settingsLink = page.locator('a[href*="sw/settings/ucp/index"], a[href*="sw.settings.ucp.index"]').first();
  await settingsLink.waitFor({ state: 'visible', timeout: routeTimeout });
}

async function assertOk(response, message) {
  if (!response.ok()) {
    fail(`${message} HTTP ${response.status()}: ${await response.text()}`);
  }
}

function assertEqualArrays(actual, expected, message) {
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    fail(`${message}\nExpected: ${JSON.stringify(expected)}\nActual:   ${JSON.stringify(actual)}`);
  }
}

function parseArgs(argv) {
  const parsed = {};

  for (let index = 0; index < argv.length; index += 1) {
    const entry = argv[index];

    if (!entry.startsWith('--')) {
      continue;
    }

    const [rawKey, rawValue] = entry.slice(2).split('=', 2);
    const key = rawKey.replace(/-([a-z])/g, (_, char) => char.toUpperCase());

    if (rawValue !== undefined) {
      parsed[key] = rawValue;
      continue;
    }

    parsed[key] = argv[index + 1] && !argv[index + 1].startsWith('--') ? argv[++index] : '1';
  }

  return parsed;
}

function stripTrailingSlash(value) {
  return value.replace(/\/+$/, '');
}

function safeName(value) {
  return String(value).replace(/[^a-z0-9._-]+/gi, '-').replace(/^-|-$/g, '') || 'unknown';
}

function fail(message) {
  throw new Error(message);
}
