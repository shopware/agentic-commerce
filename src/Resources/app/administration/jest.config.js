const { join, resolve } = require('path');

const artifactsPath = process.env.ARTIFACTS_PATH ? join(process.env.ARTIFACTS_PATH, '/build/artifacts/jest') : 'coverage';

// declare fallback for default setup
process.env.ADMIN_PATH = process.env.ADMIN_PATH || join(__dirname, '../../../../../../../src/Administration/Resources/app/administration');

module.exports = {
    displayName: {
        name: 'Agentic-Commerce Administration',
        color: 'lime'
    },

    preset: './node_modules/@shopware-ag/jest-preset-sw6-admin/jest-preset.js',

    globals: {
        adminPath: process.env.ADMIN_PATH,
    },

    rootDir: './',

    moduleDirectories: [
        '<rootDir>/node_modules',
        resolve(join(process.env.ADMIN_PATH, '/node_modules')),
    ],

    testMatch: [
        '<rootDir>/test/**/*.spec.js',
    ],

    collectCoverage: true,

    coverageDirectory: artifactsPath,

    coverageReporters: [
        'text',
        'cobertura',
    ],

    collectCoverageFrom: [
        '<rootDir>/src/**/*.(j|t)s',
    ],

    coverageProvider: 'v8',

    setupFilesAfterEnv: [
        resolve(join(process.env.ADMIN_PATH, '/test/_setup/prepare_environment.js')),
    ],

    moduleNameMapper: {
        '^\@shopware-ag\/meteor-admin-sdk\/es\/(.*)': `${process.env.ADMIN_PATH}/node_modules/@shopware-ag/meteor-admin-sdk/umd/$1`,
        '^@shopware-ag/meteor-component-library$': `${process.env.ADMIN_PATH}/node_modules/@shopware-ag/meteor-component-library/dist/common/index.js`,
        '^@shopware-ag/meteor-component-library/dist/esm/(.*)$': `${process.env.ADMIN_PATH}/node_modules/@shopware-ag/meteor-component-library/dist/common/$1`,
        '^@administration(.*)$': `${process.env.ADMIN_PATH}/src$1`,
        '^lodash-es$': 'lodash',
        '^lodash-es/(.*)$': 'lodash/$1',
        vue$: `${process.env.ADMIN_PATH}/node_modules/vue/dist/vue.cjs.js`,
        '^@vue/shared$': `${process.env.ADMIN_PATH}/node_modules/@vue/shared/dist/shared.cjs.js`,
    },

    // Override transformer so @babel/preset-typescript runs before @babel/preset-env,
    // which is required for TypeScript constructor parameter properties (public readonly)
    // used in newer trunk admin source files (e.g. core/telemetry/types.ts).
    transform: {
        '^.+\\.(js|jsx|ts|tsx)$': ['babel-jest', {
            presets: [
                ['@babel/preset-env', { targets: { node: 'current' } }],
                ['@babel/preset-typescript', { allExtensions: true }],
            ],
            plugins: ['shopware-vite-meta-glob'],
        }],
        '^.+(\\.twig|\\.html)$': require.resolve(
            './node_modules/@shopware-ag/jest-preset-sw6-admin/@tool/twig-to-vue-transformer/index.js'
        ),
    },

    transformIgnorePatterns: [
        '/node_modules/(?!(@shopware-ag/meteor-component-library|@shopware-ag/meteor-icon-kit|uuidv7|other)/)',
    ],

    testEnvironmentOptions: {
        customExportConditions: ['node', 'node-addons'],
    },
};
