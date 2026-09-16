import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'node:path';
import process from 'node:process';

dotenv.config({ quiet: true });

const missingEnvVars = ['APP_URL'].filter(envVar => process.env[envVar] === undefined);

if (missingEnvVars.length > 0) {
    process.stdout.write(`Please provide the following env vars (loaded env: ${path.resolve('.env')}):\n`);
    process.stdout.write('- ' + missingEnvVars.join('\n- ') + '\n');

    process.exit(1);
}

process.env['SHOPWARE_ADMIN_USERNAME'] = process.env['SHOPWARE_ADMIN_USERNAME'] || 'admin';
process.env['SHOPWARE_ADMIN_PASSWORD'] = process.env['SHOPWARE_ADMIN_PASSWORD'] || 'shopware';

const ignoreHTTPSErrors
    = process.env.SHOPWARE_PLAYWRIGHT_IGNORE_HTTPS_ERRORS === 'true'
      || process.env.SHOPWARE_PLAYWRIGHT_IGNORE_HTTPS_ERRORS === '1';

// Kept for parity with core's config. Nothing reads these today: the ATS talks to the Admin API,
// the Store API and Mailpit, and ships no database driver. See README, "Environment".
if (process.env.DATABASE_URL) {
    const matches = process.env.DATABASE_URL.match(/mysql:\/\/([^:@]+)(:([^:@]+))?@([^/]+)\/([^?]+)/);
    if (matches) {
        process.env.ATS_DATABASE_USERNAME = process.env.ATS_DATABASE_USERNAME || matches[1];
        process.env.ATS_DATABASE_PASSWORD = process.env.ATS_DATABASE_PASSWORD || matches[3] || '';
        process.env.ATS_DATABASE_HOST = process.env.ATS_DATABASE_HOST || matches[4];
        process.env.ATS_DATABASE_NAME = process.env.ATS_DATABASE_NAME || matches[5];
    }
}

const withTrailingSlash = (url: string): string => url.replace(/\/+$/, '') + '/';

const appUrl = withTrailingSlash(process.env['APP_URL'] ?? '');
const adminUrl = process.env['ADMIN_URL'] ? withTrailingSlash(process.env['ADMIN_URL']) : `${appUrl}admin/`;

process.env['APP_URL'] = appUrl;
process.env['ADMIN_URL'] = adminUrl;

const browser = {
    ...devices['Desktop Chrome'],
    // The device default (1280x720) hides the admin menu off-canvas.
    viewport: { width: 1920, height: 1080 },
};

export default defineConfig({
    testDir: './tests',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: 'html',
    timeout: 60_000,
    expect: {
        timeout: 15_000,
    },

    use: {
        baseURL: appUrl,
        trace: 'retain-on-failure',
        video: 'off',
        ignoreHTTPSErrors,
        // A UCP signature is valid for 300 seconds. An unpinned browser clock drifts against the
        // server and turns every signed journey flaky.
        timezoneId: 'UTC',
    },

    // Abused to wait for the externally started Shopware.
    webServer: {
        command: 'sleep 1d',
        url: appUrl,
        reuseExistingServer: true,
        ignoreHTTPSErrors,
    },

    projects: [
        {
            name: 'Signer',
            grep: /@Signer/,
        },
        {
            name: 'Setup',
            use: { ...browser },
            grep: /@Setup/,
        },
        {
            name: 'UcpProtocol',
            grep: /@UcpProtocol/,
            dependencies: ['Signer', 'Setup'],
        },
        {
            name: 'UcpContent',
            grep: /@UcpContent/,
            dependencies: ['Signer', 'Setup'],
        },
        {
            name: 'UcpEmbedded',
            use: { ...browser },
            grep: /@UcpEmbedded/,
            dependencies: ['Signer', 'Setup'],
        },
        {
            name: 'UcpAdmin',
            use: { ...browser },
            grep: /@UcpAdmin/,
            dependencies: ['Signer', 'Setup'],
        },
        {
            name: 'UcpAcl',
            use: { ...browser },
            grep: /@UcpAcl/,
            dependencies: ['Signer', 'Setup'],
        },
        {
            name: 'UcpSerial',
            use: { ...browser },
            grep: /@UcpSerial/,
            dependencies: ['Signer', 'Setup'],
            workers: 1,
        },
        {
            // Non-gating: excluded from `npm test` via --grep-invert, run by `npm run test:known-blocked`.
            name: 'UcpKnownBlocked',
            use: { ...browser },
            grep: /@UcpKnownBlocked/,
            dependencies: ['Signer', 'Setup'],
        },
    ],
});
