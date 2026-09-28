/**
 * Translates outside a component.
 *
 * `$t` is installed on the Vue app's `globalProperties` (Vue 3) or on the root
 * instance itself (Vue 2), and code running in a router hook has neither `this`
 * nor a component context. Falling back to the key keeps a missing translator
 * from throwing inside a navigation guard, where it would surface as nothing at
 * all.
 */
export function translateAdmin(key, params = undefined) {
    const root = Shopware.Application?.getApplicationRoot?.();

    if (!root) {
        return key;
    }

    const translate = root.config?.globalProperties?.$t
        ?? root.config?.globalProperties?.$tc
        ?? root.$t
        ?? root.$tc;

    if (typeof translate !== 'function') {
        return key;
    }

    try {
        const translated = params === undefined ? translate.call(root, key) : translate.call(root, key, params);

        return typeof translated === 'string' && translated !== '' ? translated : key;
    } catch {
        return key;
    }
}