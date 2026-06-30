import {
    parseMajorMinor,
    isVersionAtLeast,
    currentAdminVersion,
    useMtComponents,
} from '../../../../../src/extension/sw-sales-channel/agentic-commerce/admin-version';

describe('agentic-commerce/admin-version', () => {
    describe('parseMajorMinor', () => {
        it('parses a release version', () => {
            expect(parseMajorMinor('6.6.10.0')).toEqual({ major: 6, minor: 6 });
        });

        it('parses the dev/trunk marker', () => {
            expect(parseMajorMinor('6.7.9999999.9999999')).toEqual({ major: 6, minor: 7 });
        });

        it.each([null, undefined, 42, '', 'nonsense'])('returns null for invalid input %p', (value) => {
            expect(parseMajorMinor(value)).toBeNull();
        });
    });

    describe('isVersionAtLeast', () => {
        it.each([
            ['6.6.0.0', 6, 6, true],
            ['6.7.9999999.9999999', 6, 6, true],
            ['7.0.0.0', 6, 6, true],
            ['6.5.9.0', 6, 6, false],
            ['6.4.20.0', 6, 6, false],
            ['', 6, 6, false],
        ])('%s >= %i.%i → %p', (version, major, minor, expected) => {
            expect(isVersionAtLeast(version, major, minor)).toBe(expected);
        });
    });

    describe('useMtComponents', () => {
        it('is true on 6.6 and 6.7', () => {
            expect(useMtComponents('6.6.0.0')).toBe(true);
            expect(useMtComponents('6.7.9999999.9999999')).toBe(true);
        });

        it('is false on 6.5 (legacy sw-* path)', () => {
            expect(useMtComponents('6.5.8.0')).toBe(false);
        });

        it('falls back to legacy when the version is unknown', () => {
            expect(useMtComponents('')).toBe(false);
        });
    });

    describe('currentAdminVersion', () => {
        const originalShopware = global.Shopware;

        afterEach(() => {
            global.Shopware = originalShopware;
        });

        it('reads Shopware.Context.app.config.version', () => {
            global.Shopware = { Context: { app: { config: { version: '6.6.10.0' } } } };
            expect(currentAdminVersion()).toBe('6.6.10.0');
        });

        it('returns an empty string when unavailable', () => {
            global.Shopware = {};
            expect(currentAdminVersion()).toBe('');
        });
    });
});
