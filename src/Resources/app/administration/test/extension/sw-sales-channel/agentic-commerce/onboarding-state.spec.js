import {
    needsOnboarding,
    shouldOfferOnboarding,
} from 'Resources/extension/sw-sales-channel/agentic-commerce/onboarding-state';

describe('onboarding-state', () => {
    const channels = [
        { id: 'storefront', name: 'Storefront', ucp: { active: false }, domains: [{ url: 'https://shop.example' }] },
        { id: 'headless', name: 'Headless', ucp: { active: false }, domains: [] },
        { id: 'b2b', name: 'B2B', ucp: { active: true }, domains: [{ url: 'https://b2b.example' }] },
    ];

    it('needs onboarding while no channel exposes UCP', () => {
        expect(needsOnboarding([channels[0], channels[1]])).toBe(true);
    });

    it('does not need onboarding once any channel is exposed', () => {
        expect(needsOnboarding(channels)).toBe(false);
    });

    it('does not need onboarding when there is no channel to offer', () => {
        expect(needsOnboarding([])).toBe(false);
    });

    it('offers onboarding on boot while nothing is exposed', () => {
        expect(shouldOfferOnboarding({ channels: [channels[0]], currentRouteName: 'sw.dashboard.index' })).toBe(true);
    });

    it('does not offer onboarding once a channel is exposed', () => {
        expect(shouldOfferOnboarding({ channels, currentRouteName: 'sw.dashboard.index' })).toBe(false);
    });

    it('does not offer onboarding to a user who dismissed it', () => {
        expect(shouldOfferOnboarding({ dismissed: true, channels: [channels[0]], currentRouteName: 'sw.dashboard.index' })).toBe(false);
    });

    it('does not offer the page the merchant is already looking at', () => {
        expect(shouldOfferOnboarding({ channels: [channels[0]], currentRouteName: 'sw.settings.agentic.commerce.index' })).toBe(false);
    });

    it('offers onboarding only once per session', () => {
        expect(shouldOfferOnboarding({ channels: [channels[0]], currentRouteName: 'sw.dashboard.index', alreadyOffered: true })).toBe(false);
    });
});
