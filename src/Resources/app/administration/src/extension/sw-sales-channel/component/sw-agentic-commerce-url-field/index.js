const { Component } = Shopware;

// From 6.6.3 `sw-url-field` only switches between implementations; the legacy field is `sw-url-field-deprecated`.
export const legacyUrlFieldName = Component.getComponentRegistry().has('sw-url-field-deprecated')
    ? 'sw-url-field-deprecated'
    : 'sw-url-field';

export const swAgenticCommerceUrlField = {
    methods: {
        // Before 6.6.9.0 (NEXT-38862) core keeps a cleared value to itself. Vue 2 listens to `input`, Vue 3 to `update:value`.
        handleEmptyUrl() {
            this.$super('handleEmptyUrl');
            this.$emit('input', '');
            this.$emit('update:value', '');
        },
    },
};

Component.extend('sw-agentic-commerce-url-field', legacyUrlFieldName, swAgenticCommerceUrlField);
