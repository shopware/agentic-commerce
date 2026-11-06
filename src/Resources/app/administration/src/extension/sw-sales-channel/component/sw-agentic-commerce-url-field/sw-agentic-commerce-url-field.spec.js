/**
 * @sw-package discovery
 */

function loadWithRegisteredComponents(componentNames) {
    const extend = jest.fn();
    global.Shopware = {
        Component: {
            extend,
            getComponentRegistry: () => new Map(componentNames.map((name) => [name, {}])),
        },
    };

    let module;
    jest.isolateModules(() => {
        module = require('./index');
    });

    return { extend, module };
}

describe('sw-agentic-commerce-url-field', () => {
    it('reports a cleared value in both Vue 2 and Vue 3 event names', () => {
        const { module } = loadWithRegisteredComponents(['sw-url-field']);
        const field = { $super: jest.fn(), $emit: jest.fn() };

        module.swAgenticCommerceUrlField.methods.handleEmptyUrl.call(field);

        expect(field.$super).toHaveBeenCalledWith('handleEmptyUrl');
        expect(field.$emit).toHaveBeenCalledWith('input', '');
        expect(field.$emit).toHaveBeenCalledWith('update:value', '');
    });

    it('extends the legacy url field on 6.5', () => {
        const { extend } = loadWithRegisteredComponents(['sw-url-field']);

        expect(extend).toHaveBeenCalledWith('sw-agentic-commerce-url-field', 'sw-url-field', expect.any(Object));
    });

    it('extends the deprecated url field where sw-url-field is only a switching wrapper', () => {
        const { extend } = loadWithRegisteredComponents(['sw-url-field', 'sw-url-field-deprecated']);

        expect(extend).toHaveBeenCalledWith('sw-agentic-commerce-url-field', 'sw-url-field-deprecated', expect.any(Object));
    });
});
