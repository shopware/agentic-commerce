import {
    emptyReadiness,
    normalizeReadiness,
    statusVariant,
    statusSnippet,
    progressPercent,
    readinessTasks,
    preparedChannels,
    unpreparedChannels,
    actionableChannelFindings,
    hasChannelsToPrepare,
    prepareRows,
    defaultPrepareSelection,
    STATUS_READY,
    STATUS_ACTION_NEEDED,
} from 'Resources/extension/sw-sales-channel/agentic-commerce/readiness-state';

function readiness(overrides = {}) {
    return normalizeReadiness({
        status: STATUS_ACTION_NEEDED,
        completedSteps: 2,
        totalSteps: 3,
        steps: {
            installed: { done: true },
            prepared: { done: true, count: 1 },
            connected: { done: false, count: 0 },
        },
        channels: [
            { id: 'a', name: 'Storefront', prepared: true, activeProductCount: 2030, domains: [{ url: 'https://shop' }], findings: [] },
            { id: 'b', name: 'Headless', prepared: false, activeProductCount: 2000, domains: [], findings: [] },
        ],
        ...overrides,
    });
}

describe('readiness-state', () => {
    it('falls back to an empty shape for a missing payload', () => {
        expect(normalizeReadiness(undefined)).toEqual(emptyReadiness());
        expect(normalizeReadiness('nope').channels).toEqual([]);
    });

    it('keeps the default steps when the payload only carries some of them', () => {
        const merged = normalizeReadiness({ steps: { prepared: { done: true, count: 2 } } });

        expect(merged.steps.prepared).toEqual({ done: true, count: 2 });
        expect(merged.steps.connected).toEqual({ done: false, count: 0 });
    });

    it('shows a success pill only when the backend reports ready', () => {
        expect(statusVariant(readiness({ status: STATUS_READY }))).toBe('success');
        expect(statusVariant(readiness())).toBe('warning');
    });

    it('does not infer readiness from the step count', () => {
        // Every step done, but the backend still says action_needed because a
        // prepared channel has an error-level finding.
        const allStepsDone = readiness({
            status: STATUS_ACTION_NEEDED,
            completedSteps: 3,
            steps: {
                installed: { done: true },
                prepared: { done: true, count: 1 },
                connected: { done: true, count: 1 },
            },
        });

        expect(progressPercent(allStepsDone)).toBe(100);
        expect(statusVariant(allStepsDone)).toBe('warning');
    });

    it('resolves the status snippet keys from the status', () => {
        expect(statusSnippet(readiness({ status: STATUS_READY })).heading)
            .toBe('swagAgenticCommerce.settings.status.ready.heading');
    });

    it('reports progress as a percentage and clamps an overshooting count', () => {
        expect(progressPercent(readiness({ completedSteps: 1 }))).toBe(33);
        expect(progressPercent(readiness({ completedSteps: 9 }))).toBe(100);
    });

    it('locks connecting a provider until at least one channel is prepared', () => {
        const unprepared = readiness({
            steps: {
                installed: { done: true },
                prepared: { done: false, count: 0 },
                connected: { done: false, count: 0 },
            },
        });

        expect(readinessTasks(unprepared).find((task) => task.key === 'connected').locked).toBe(true);
        expect(readinessTasks(readiness()).find((task) => task.key === 'connected').locked).toBe(false);
    });

    it('splits prepared from unprepared channels', () => {
        expect(preparedChannels(readiness()).map((c) => c.id)).toEqual(['a']);
        expect(unpreparedChannels(readiness()).map((c) => c.id)).toEqual(['b']);
        expect(hasChannelsToPrepare(readiness())).toBe(true);
    });

    it('keeps only findings a merchant can act on and names the channel', () => {
        const withFindings = readiness({
            channels: [
                {
                    id: 'a',
                    name: 'Storefront',
                    prepared: true,
                    findings: [
                        { severity: 'info', code: 'inactive', message: 'i', salesChannelName: '' },
                        { severity: 'warning', code: 'no_storefront_domain', message: 'w', salesChannelName: '' },
                        { severity: 'error', code: 'no_active_signing_key', message: 'e', salesChannelName: 'Storefront' },
                    ],
                },
            ],
        });

        const findings = actionableChannelFindings(withFindings);

        expect(findings.map((f) => f.code)).toEqual(['no_storefront_domain', 'no_active_signing_key']);
        expect(findings[0].salesChannelName).toBe('Storefront');
    });

    it('offers only the channels that are not prepared yet', () => {
        // An already-prepared channel is nothing for the flow to do, and offering
        // it would invite rewriting a configuration the merchant tuned.
        expect(prepareRows(readiness())).toEqual([
            {
                id: 'b',
                name: 'Headless',
                activeProductCount: 2000,
                domainCount: 0,
                hasDomain: false,
                primaryDomain: null,
            },
        ]);
    });

    it('carries the domain and product count each row shows', () => {
        const bothUnprepared = readiness({
            channels: [
                { id: 'a', name: 'Storefront', prepared: false, activeProductCount: 2030, domains: [{ url: 'https://shop' }] },
            ],
        });

        expect(prepareRows(bothUnprepared)[0]).toMatchObject({
            primaryDomain: 'https://shop',
            domainCount: 1,
            hasDomain: true,
            activeProductCount: 2030,
        });
    });

    it('drops entries without an id rather than offering a row that cannot be submitted', () => {
        const broken = readiness({ channels: [{ name: 'ghost', prepared: false }] });

        expect(prepareRows(broken)).toEqual([]);
    });

    it('preselects every offered channel', () => {
        expect(defaultPrepareSelection(prepareRows(readiness()))).toEqual(['b']);
    });
});