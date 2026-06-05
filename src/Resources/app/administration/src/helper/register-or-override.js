// Override if core already registered the component (a duplicate register is
// silently dropped), otherwise register it standalone.
export function registerOrOverride(name, config) {
    const { Component } = Shopware;

    if (Component.getComponentRegistry().has(name)) {
        Component.override(name, config);
        return;
    }

    Component.register(name, config);
}
