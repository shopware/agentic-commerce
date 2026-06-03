import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import eslint from '@eslint/js';
import globals from 'globals';
import jestPlugin from 'eslint-plugin-jest';
import eslintPluginVue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';

const require = createRequire(import.meta.url);
const projectRoot = dirname(fileURLToPath(import.meta.url));
const adminPath = resolveAdminPath();

const swCoreRules = requireIfExists(adminPath, 'eslint-rules/core-rules');
const swDeprecationRules = requireIfExists(adminPath, 'eslint-rules/deprecation-rules');
const swTestRules = requireIfExists(adminPath, 'eslint-rules/test-rules');
const twigVue = requireIfExists(adminPath, 'twigVuePlugin');
const twigVueProcessor = twigVue?.processors?.['twig-vue']
    ? {
        ...twigVue.processors['twig-vue'],
        meta: twigVue.processors['twig-vue'].meta || {
            name: 'shopware-twig-vue',
            version: 'local',
        },
    }
    : null;

const shopwareRulePlugins = {
    ...(swCoreRules ? { 'sw-core-rules': swCoreRules } : {}),
    ...(swDeprecationRules ? { 'sw-deprecation-rules': swDeprecationRules } : {}),
    ...(swTestRules ? { 'sw-test-rules': swTestRules } : {}),
};

const shopwareJsRules = {
    ...(swCoreRules ? {
        'sw-core-rules/require-position-identifier': ['warn', {
            components: [
                'sw-card',
                'mt-card',
                'sw-tabs',
            ],
        }],
        'sw-core-rules/require-package-annotation': 'off',
        'sw-core-rules/require-explicit-emits': 'off',
    } : {}),
    ...(swDeprecationRules ? {
        'sw-deprecation-rules/no-compat-conditions': ['warn', 'disableFix'],
        'sw-deprecation-rules/no-empty-listeners': ['warn', 'enableFix'],
        'sw-deprecation-rules/no-vue-options-api': 'off',
    } : {}),
    ...(swTestRules ? {
        'sw-test-rules/await-async-functions': 'warn',
    } : {}),
};

const shopwareTwigRules = {
    ...(swDeprecationRules ? {
        'sw-deprecation-rules/no-deprecated-components': 'off',
        'sw-deprecation-rules/no-deprecated-component-usage': 'off',
    } : {}),
};

export default tseslint.config(
    {
        ignores: [
            'node_modules/**',
            '.tools/**',
            'vendor/**',
            'var/**',
            'coverage/**',
            'public/**',
            'src/Resources/public/**',
            'tests/jest/administration/.jestcache/**',
            'tests/jest/administration/coverage/**',
            'var/playwright-report/**',
            'var/playwright-results/**',
        ],
    },
    eslint.configs.recommended,
    {
        files: [
            'src/Resources/app/administration/src/**/*.js',
            'tests/jest/administration/**/*.js',
            'tests/e2e/**/*.js',
            'bin/**/*.mjs',
            'playwright.config.js',
        ],
        plugins: {
            jest: jestPlugin,
            ...shopwareRulePlugins,
        },
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.node,
                ...globals.jest,
                Shopware: 'readonly',
            },
        },
        rules: {
            'no-unused-vars': ['error', {
                argsIgnorePattern: '^_',
                varsIgnorePattern: '^_',
            }],
            'jest/no-disabled-tests': 'warn',
            'jest/no-focused-tests': 'error',
            ...shopwareJsRules,
        },
    },
    ...(twigVueProcessor ? [{
        files: ['src/Resources/app/administration/src/**/*.html.twig'],
        extends: [
            ...eslintPluginVue.configs['flat/recommended'],
        ],
        plugins: {
            'twig-vue': twigVue,
            ...shopwareRulePlugins,
        },
        processor: twigVueProcessor,
        languageOptions: {
            globals: {
                ...globals.browser,
                Shopware: 'readonly',
            },
        },
        rules: {
            'vue/component-name-in-template-casing': ['error', 'kebab-case', {
                registeredComponentsOnly: true,
                ignores: [],
            }],
            'vue/attribute-hyphenation': 'off',
            'vue/attributes-order': 'off',
            'vue/html-closing-bracket-newline': 'off',
            'vue/html-indent': ['error', 4, {
                baseIndent: 0,
            }],
            'vue/html-self-closing': ['error', {
                html: {
                    void: 'never',
                    normal: 'never',
                    component: 'always',
                },
                svg: 'always',
                math: 'always',
            }],
            'vue/max-attributes-per-line': 'off',
            'vue/no-deprecated-filter': 'error',
            'vue/no-deprecated-dollar-listeners-api': 'error',
            'vue/no-deprecated-dollar-scopedslots-api': 'error',
            'vue/no-deprecated-v-on-native-modifier': 'error',
            'vue/no-lone-template': 'error',
            'vue/no-multiple-template-root': 'off',
            'vue/no-parsing-error': ['error', {
                'nested-comment': false,
            }],
            'vue/no-template-shadow': 'off',
            'vue/no-v-for-template-key': 'off',
            'vue/no-v-for-template-key-on-child': 'error',
            'vue/no-v-html': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/valid-template-root': 'off',
            'vue/valid-v-slot': ['error', {
                allowModifiers: true,
            }],
            ...shopwareTwigRules,
        },
    }] : []),
);

function resolveAdminPath() {
    const candidates = [
        process.env.ADMIN_PATH,
        process.env.SHOPWARE_PROJECT_DIR
            ? join(process.env.SHOPWARE_PROJECT_DIR, 'src/Administration/Resources/app/administration')
            : null,
        join(projectRoot, '../shopware/src/Administration/Resources/app/administration'),
        join(projectRoot, '../shopware-trunk/src/Administration/Resources/app/administration'),
        join(projectRoot, '../shopware-6-6-branch/src/Administration/Resources/app/administration'),
        join(projectRoot, '../shopware-6-5-branch/src/Administration/Resources/app/administration'),
    ];

    return candidates.find((candidate) => candidate && existsSync(candidate)) ?? null;
}

function requireIfExists(basePath, relativePath) {
    if (!basePath) {
        return null;
    }

    const absolutePath = resolve(basePath, relativePath);
    if (!existsSync(absolutePath)) {
        return null;
    }

    return require(absolutePath);
}
