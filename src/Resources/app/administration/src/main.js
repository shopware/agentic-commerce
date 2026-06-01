// Keep the administration boot blocked until the plugin registers its
// services, routes, and component overrides. Trunk's async admin loader can
// otherwise render Settings and Sales Channel views before our module is ready.
(async () => {
    const finishPluginBoot = Shopware.Plugin.addBootPromise();

    try {
        await Promise.all([
            import('./core/service/api/ucp-admin.api.service.js'),
            import('./module/sw-settings-ucp/index.js'),
        ]);
    } catch (error) {
        // Failing closed would take the whole administration down. Keep the
        // shell bootable and surface the registration failure in the console.
        // eslint-disable-next-line no-console
        console.error(error);
    } finally {
        finishPluginBoot();
    }
})();
