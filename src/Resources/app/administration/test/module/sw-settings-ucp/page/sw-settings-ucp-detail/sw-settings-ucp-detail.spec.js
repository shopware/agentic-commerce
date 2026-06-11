/**
 * @sw-package discovery
 *
 * Locks in the cross-version value-binding guard for the UCP detail form.
 *
 * 6.5 (VUE3 flag off) emits `change` with the value; 6.6+ emits `update:value`,
 * and on 6.6+ a native `change` can fall through as a DOM Event. The guarded
 * setters must apply real values (so 6.5 deactivation persists) and ignore
 * Event instances (so 6.6/6.7 are never clobbered).
 */

const Shopware = {
    Mixin: { getByName: () => ({}) },
    compatConfig: {},
};

global.Shopware = Shopware;

jest.mock(
    '../../../../../src/module/sw-settings-ucp/page/sw-settings-ucp-detail/sw-settings-ucp-detail.html.twig',
    () => 'mock-template',
    { virtual: true },
);
jest.mock(
    '../../../../../src/module/sw-settings-ucp/page/sw-settings-ucp-detail/sw-settings-ucp-detail.scss',
    () => ({}),
    { virtual: true },
);

// eslint-disable-next-line import/first
const { methods } = require('../../../../../src/module/sw-settings-ucp/page/sw-settings-ucp-detail').default;

const { setValue, setKeyValue, setHostList, updateCapability, updateTransport } = methods;

describe('sw-settings-ucp-detail', () => {
    it('setValue applies a real value (6.5 emits `change` with the value)', () => {
        const ctx = { form: { active: true } };

        setValue.call(ctx, 'active', false);

        expect(ctx.form.active).toBe(false);
    });

    it('setValue ignores a DOM Event (6.6/6.7 native `change` fallthrough)', () => {
        const ctx = { form: { active: true } };

        setValue.call(ctx, 'active', new Event('change'));

        expect(ctx.form.active).toBe(true);
    });

    it('setKeyValue applies a real value and ignores a DOM Event', () => {
        const ctx = { newKeyKid: '' };

        setKeyValue.call(ctx, 'newKeyKid', 'key-1');
        expect(ctx.newKeyKid).toBe('key-1');

        setKeyValue.call(ctx, 'newKeyKid', new Event('change'));
        expect(ctx.newKeyKid).toBe('key-1');
    });

    it('setHostList applies a filtered array and never clears it on a DOM Event', () => {
        const ctx = { form: { agentAllowlist: [] } };

        setHostList.call(ctx, 'agentAllowlist', ['a.example', '']);
        expect(ctx.form.agentAllowlist).toEqual(['a.example']);

        setHostList.call(ctx, 'agentAllowlist', new Event('change'));
        expect(ctx.form.agentAllowlist).toEqual(['a.example']);
    });

    it('updateCapability toggles on a real value and ignores a DOM Event', () => {
        const ctx = { form: { enabledCapabilities: ['catalog'] } };

        updateCapability.call(ctx, 'cart', true);
        expect(ctx.form.enabledCapabilities).toContain('cart');

        updateCapability.call(ctx, 'order', new Event('change'));
        expect(ctx.form.enabledCapabilities).not.toContain('order');
    });

    it('updateTransport ignores a DOM Event (no transport added)', () => {
        const ctx = { form: { enabledTransports: ['rest'] }, transportOptions: [] };

        updateTransport.call(ctx, 'sse', new Event('change'));

        expect(ctx.form.enabledTransports).toEqual(['rest']);
    });
});
