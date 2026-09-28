import {
    STEP_UNDERSTAND,
    STEP_SELECT,
    STEP_REVIEW,
    PREPARE_STEPS,
    stepIndex,
    nextStep,
    previousStep,
    isFirstStep,
    stepState,
    stepItems,
    buildPreparePayload,
    canLeaveStep,
    toggleSelection,
    selectedRows,
    outcomesById,
    actionableOutcomeFindings,
    hasFailures,
} from 'Resources/extension/sw-sales-channel/agentic-commerce/prepare-flow';

const rows = [
    { id: 'a', name: 'Storefront' },
    { id: 'b', name: 'Headless' },
];

describe('prepare-flow', () => {
    it('walks the three steps forward and back without running off either end', () => {
        expect(PREPARE_STEPS).toEqual([STEP_UNDERSTAND, STEP_SELECT, STEP_REVIEW]);
        expect(nextStep(STEP_UNDERSTAND)).toBe(STEP_SELECT);
        expect(nextStep(STEP_REVIEW)).toBe(STEP_REVIEW);
        expect(previousStep(STEP_SELECT)).toBe(STEP_UNDERSTAND);
        expect(previousStep(STEP_UNDERSTAND)).toBe(STEP_UNDERSTAND);
    });

    it('treats an unknown step as the first one', () => {
        expect(stepIndex('nonsense')).toBe(0);
        expect(isFirstStep(STEP_UNDERSTAND)).toBe(true);
        expect(isFirstStep(STEP_SELECT)).toBe(false);
    });

    it('marks passed steps done, the current one active and the rest todo', () => {
        expect(stepState(STEP_UNDERSTAND, STEP_SELECT)).toBe('done');
        expect(stepState(STEP_SELECT, STEP_SELECT)).toBe('active');
        expect(stepState(STEP_REVIEW, STEP_SELECT)).toBe('todo');
    });

    it('numbers the stepper items and resolves their labels', () => {
        const items = stepItems(STEP_SELECT);

        expect(items.map((item) => item.number)).toEqual([1, 2, 3]);
        expect(items.map((item) => item.state)).toEqual(['done', 'active', 'todo']);
        expect(items[0].label).toBe('swagAgenticCommerce.prepare.steps.understand');
    });

    it('sends only the channel ids so the endpoint applies its own defaults', () => {
        const payload = buildPreparePayload(['a', 'b']);

        expect(payload).toEqual({ salesChannelIds: ['a', 'b'] });
        expect(payload).not.toHaveProperty('enabledCapabilities');
        expect(payload).not.toHaveProperty('enabledTransports');
        expect(payload).not.toHaveProperty('profileDomain');
    });

    it('copies the selection so a later edit cannot mutate a sent payload', () => {
        const selected = ['a'];
        const payload = buildPreparePayload(selected);

        selected.push('b');

        expect(payload.salesChannelIds).toEqual(['a']);
    });

    it('lets the merchant read the explanation but not leave the selection empty', () => {
        expect(canLeaveStep(STEP_UNDERSTAND, [])).toBe(true);
        expect(canLeaveStep(STEP_SELECT, [])).toBe(false);
        expect(canLeaveStep(STEP_SELECT, ['a'])).toBe(true);
        expect(canLeaveStep(STEP_REVIEW, [])).toBe(false);
    });

    it('toggles a selection without duplicating an id', () => {
        expect(toggleSelection(['a'], 'b', true)).toEqual(['a', 'b']);
        expect(toggleSelection(['a', 'b'], 'a', false)).toEqual(['b']);
        expect(toggleSelection(['a'], 'a', true)).toEqual(['a']);
    });

    it('resolves the selected rows in list order', () => {
        expect(selectedRows(rows, ['b']).map((row) => row.name)).toEqual(['Headless']);
        expect(selectedRows(rows, [])).toEqual([]);
    });

    it('indexes outcomes by id and survives a missing result', () => {
        expect(outcomesById({ results: [{ salesChannelId: 'a', status: 'enabled' }] }).a.status).toBe('enabled');
        expect(outcomesById(null)).toEqual({});
    });

    it('keeps only findings that need an action', () => {
        const findings = actionableOutcomeFindings({
            results: [{
                findings: [
                    { severity: 'info', code: 'inactive' },
                    { severity: 'warning', code: 'no_storefront_domain' },
                    { severity: 'error', code: 'no_active_signing_key' },
                ],
            }],
        });

        expect(findings.map((f) => f.code)).toEqual(['no_storefront_domain', 'no_active_signing_key']);
    });

    it('reports whether any channel failed', () => {
        expect(hasFailures({ enabled: 2, skipped: 0, failed: 1 })).toBe(true);
        expect(hasFailures({ enabled: 2, skipped: 0, failed: 0 })).toBe(false);
        expect(hasFailures(null)).toBe(false);
    });
});