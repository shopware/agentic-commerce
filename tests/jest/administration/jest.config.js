import { existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = resolve(__dirname, '../../..');
const artifactsPath = process.env.ARTIFACTS_PATH
    ? join(process.env.ARTIFACTS_PATH, '/build/artifacts/jest')
    : '<rootDir>/tests/jest/administration/coverage';
const isCi = process.argv.includes('--ci');
const isDocker = existsSync('/.dockerenv');

export default {
    rootDir,
    cacheDirectory: '<rootDir>/tests/jest/administration/.jestcache',
    cache: true,
    clearMocks: true,
    restoreMocks: true,
    testEnvironment: 'jsdom',
    maxWorkers: process.env.JEST_MAX_WORKERS || (isDocker ? '100%' : '50%'),
    workerIdleMemoryLimit: '512MB',
    testTimeout: process.env.JEST_TEST_TIMEOUT ? Number(process.env.JEST_TEST_TIMEOUT) : (isCi || isDocker ? 10000 : 5000),
    moduleFileExtensions: [
        'js',
        'json',
    ],
    testMatch: [
        '<rootDir>/tests/jest/administration/**/*.spec.js',
        // Extracted UCP logic specs live next to the admin source tree.
        '<rootDir>/src/Resources/app/administration/test/**/*.spec.js',
    ],
    collectCoverage: isCi,
    coverageDirectory: artifactsPath,
    coverageProvider: 'v8',
    coverageReporters: [
        isCi ? 'text-summary' : 'text',
        'cobertura',
        'html-spa',
    ],
    collectCoverageFrom: [
        '<rootDir>/src/Resources/app/administration/src/**/*.js',
    ],
    setupFilesAfterEnv: [
        '<rootDir>/tests/jest/administration/_setup/setup-shopware.js',
    ],
    transform: {
        '^.+\\.js$': ['@swc/jest', {
            jsc: {
                parser: { syntax: 'ecmascript' },
                target: 'es2021',
            },
            module: {
                type: 'commonjs',
            },
        }],
        '^.+(\\.twig|\\.html)$': '<rootDir>/tests/jest/administration/_setup/twigToVueTransformer.cjs',
    },
    moduleNameMapper: {
        '\\.(css|less|scss)$': '<rootDir>/tests/jest/administration/_mocks_/styleMock.cjs',
        '^Resources(.*)$': '<rootDir>/src/Resources/app/administration/src$1',
    },
    reporters: isCi ? [
        'default',
        ['jest-junit', {
            suiteName: 'SwagAgenticCommerce Administration Unit Tests',
            outputDirectory: artifactsPath,
            outputName: 'administration.junit.xml',
        }],
    ] : [
        'default',
    ],
    testEnvironmentOptions: {
        customExportConditions: ['node', 'node-addons'],
    },
};
