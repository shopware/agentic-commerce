(function bootstrapLegacyAdministrationBundle() {
    const globalObject = window;

    if (globalObject.__swagAgenticCommerceLegacyBootRequested) {
        return;
    }

    globalObject.__swagAgenticCommerceLegacyBootRequested = true;

    const finishPluginBoot = globalObject.Shopware?.Plugin?.addBootPromise
        ? globalObject.Shopware.Plugin.addBootPromise()
        : () => {};

    const currentScript = document.currentScript;

    function administrationBaseUrl() {
        if (currentScript?.src) {
            return new URL('../', currentScript.src).toString();
        }

        const assetBase = globalObject.__sw__?.assetPath ?? '';

        return `${assetBase}/bundles/swagagenticcommerce/administration/`;
    }

    function resolveAssetUrl(path, baseUrl) {
        if (/^https?:\/\//.test(path)) {
            return path;
        }

        if (path.startsWith('/')) {
            return `${globalObject.location.origin}${path}`;
        }

        return new URL(path, baseUrl).toString();
    }

    function ensureStylesheet(href) {
        if (document.querySelector(`link[rel="stylesheet"][href="${href}"]`)) {
            return;
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }

    async function loadLegacyEntry() {
        const baseUrl = administrationBaseUrl();
        const response = await fetch(new URL('.vite/entrypoints.json', baseUrl).toString(), { credentials: 'same-origin' });

        if (!response.ok) {
            throw new Error(`Unable to load SwagAgenticCommerce administration entrypoints (${response.status}).`);
        }

        const entrypoints = await response.json();
        const entry = entrypoints?.entryPoints?.['swag-agentic-commerce'];

        if (!entry) {
            throw new Error('SwagAgenticCommerce administration entrypoint metadata is missing.');
        }

        for (const stylesheet of entry.css ?? []) {
            ensureStylesheet(resolveAssetUrl(stylesheet, baseUrl));
        }

        await Promise.all((entry.js ?? []).map((scriptPath) => import(resolveAssetUrl(scriptPath, baseUrl))));
    }

    loadLegacyEntry()
        .catch((error) => {
            console.error(error);
        })
        .finally(() => {
            finishPluginBoot();
        });
})();
