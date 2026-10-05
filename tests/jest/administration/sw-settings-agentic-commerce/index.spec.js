global.Shopware = {
    Component: {
        override: jest.fn(),
        register: jest.fn(),
        getComponentRegistry: () => ({ has: () => false }),
    },
    Mixin: { getByName: jest.fn(() => ({})) },
    Service: jest.fn(),
    // Needed because requiring the override pulls in the prepare-modal module.
    Data: { Criteria: class {}, EntityCollection: class {} },
};

const { swSettingsAgenticCommerceOverride } = require('Resources/extension/sw-settings-agentic-commerce');

const { canViewUcpStatus, allSalesChannelsPrepared, steps, canPrepareSalesChannels, readinessSubtitle, extensionStatusLabel } =
    swSettingsAgenticCommerceOverride.computed;
const { loadReadinessCounts, applyReadiness, onPrepareSalesChannels, onPrepareModalClose, onSalesChannelsPrepared } =
    swSettingsAgenticCommerceOverride.methods;

describe('sw-settings-agentic-commerce override', () => {
    beforeEach(() => {
        Shopware.Service.mockReset();
    });

    it('exposes canViewUcpStatus based on the ucp.viewer privilege', () => {
        expect(canViewUcpStatus.call({ acl: { can: () => true } })).toBe(true);
        expect(canViewUcpStatus.call({ acl: { can: () => false } })).toBe(false);
        expect(canViewUcpStatus.call({})).toBe(false);
    });

    describe('readiness counts', () => {
        it('does not call the readiness API without ucp.viewer', () => {
            loadReadinessCounts.call({ canViewUcpStatus: false });

            expect(Shopware.Service).not.toHaveBeenCalled();
        });

        it('fetches the readiness data and hands it to applyReadiness when ucp.viewer is granted', async () => {
            const summary = {
                preparedSalesChannels: 2,
                agenticSalesChannels: 1,
                transactionalSalesChannels: 3,
            };
            const getReadiness = jest.fn(() => Promise.resolve({ data: { data: summary } }));
            Shopware.Service.mockReturnValue({ getReadiness });

            const context = { canViewUcpStatus: true, applyReadiness: jest.fn() };
            loadReadinessCounts.call(context);
            await Promise.resolve();

            expect(Shopware.Service).toHaveBeenCalledWith('ucpAdminApiService');
            expect(context.applyReadiness).toHaveBeenCalledWith(summary);
        });

        it('falls back to zero counts when the response omits them', () => {
            const context = {};
            applyReadiness.call(context, {});

            expect(context.preparedSalesChannelCount).toBe(0);
            expect(context.agenticSalesChannelCount).toBe(0);
            expect(context.transactionalSalesChannelCount).toBe(0);
        });
    });

    describe('prepare-step completion', () => {
        it('is not complete until every transactional channel is prepared', () => {
            expect(
                allSalesChannelsPrepared.call({
                    preparedSalesChannelCount: 1,
                    transactionalSalesChannelCount: 3,
                }),
            ).toBe(false);
        });

        it('is complete once prepared reaches the transactional total', () => {
            expect(
                allSalesChannelsPrepared.call({
                    preparedSalesChannelCount: 3,
                    transactionalSalesChannelCount: 3,
                }),
            ).toBe(true);
        });

        it('is never complete before the counts have loaded', () => {
            expect(
                allSalesChannelsPrepared.call({
                    preparedSalesChannelCount: 0,
                    transactionalSalesChannelCount: 0,
                }),
            ).toBe(false);
        });

        it('completes the extension step with the active description', () => {
            const context = {
                allSalesChannelsPrepared: false,
                $t: (key) => key,
                $super: () => [{ key: 'extension', complete: false, description: 'install-desc' }],
            };

            const extensionStep = steps.call(context).find((step) => step.key === 'extension');

            expect(extensionStep.complete).toBe(true);
            expect(extensionStep.description).toBe('swagAgenticCommerce.extensionStep.activeDescription');
        });

        it('marks the prepareSalesChannels step complete and swaps its description when prepared', () => {
            const context = {
                allSalesChannelsPrepared: true,
                $t: (key) => key,
                $super: () => [
                    { key: 'extension', complete: false, description: 'install-desc' },
                    { key: 'prepareSalesChannels', complete: false, description: 'pending-desc' },
                ],
            };

            const result = steps.call(context);
            const prepareStep = result.find((step) => step.key === 'prepareSalesChannels');

            expect(prepareStep.complete).toBe(true);
            expect(prepareStep.description).toBe('swagAgenticCommerce.prepareStep.completedDescription');
            expect(result.find((step) => step.key === 'extension').complete).toBe(true);
        });

        it('keeps the pending description while not every channel is prepared', () => {
            const context = {
                allSalesChannelsPrepared: false,
                $t: (key) => key,
                $super: () => [{ key: 'prepareSalesChannels', complete: false, description: 'pending-desc' }],
            };

            const prepareStep = steps.call(context).find((step) => step.key === 'prepareSalesChannels');

            expect(prepareStep.complete).toBe(false);
            expect(prepareStep.description).toBe('pending-desc');
        });
    });

    describe('canPrepareSalesChannels', () => {
        const editorAcl = { can: (key) => key === 'ucp.editor' };

        it('is enabled while unprepared transactional channels remain', () => {
            expect(
                canPrepareSalesChannels.call({
                    acl: editorAcl,
                    preparedSalesChannelCount: 1,
                    transactionalSalesChannelCount: 3,
                }),
            ).toBe(true);
        });

        it('is disabled once all transactional channels are prepared', () => {
            expect(
                canPrepareSalesChannels.call({
                    acl: editorAcl,
                    preparedSalesChannelCount: 3,
                    transactionalSalesChannelCount: 3,
                }),
            ).toBe(false);
        });

        it('is disabled without the ucp.editor privilege', () => {
            expect(
                canPrepareSalesChannels.call({
                    acl: { can: () => false },
                    preparedSalesChannelCount: 1,
                    transactionalSalesChannelCount: 3,
                }),
            ).toBe(false);
        });
    });

    describe('installed-state texts', () => {
        it('uses the active subtitle while setup is pending', () => {
            expect(readinessSubtitle.call({ isReady: false, $t: (key) => key })).toBe(
                'swagAgenticCommerce.subtitleActive',
            );
        });

        it('uses the ready subtitle once everything is prepared', () => {
            expect(readinessSubtitle.call({ isReady: true, $t: (key) => key })).toBe('swagAgenticCommerce.subtitleReady');
        });

        it('reports the extension as installed', () => {
            expect(extensionStatusLabel.call({ $t: (key) => key })).toBe(
                'swagAgenticCommerce.shopReadiness.extensionInstalled',
            );
        });
    });

    describe('prepare modal', () => {
        it('opens the modal on prepare', () => {
            const context = { showPrepareModal: false };
            onPrepareSalesChannels.call(context);

            expect(context.showPrepareModal).toBe(true);
        });

        it('closes the modal on cancel', () => {
            const context = { showPrepareModal: true };
            onPrepareModalClose.call(context);

            expect(context.showPrepareModal).toBe(false);
        });

        it('applies the returned summary and closes the modal after preparing', () => {
            const summary = { preparedSalesChannels: 3, agenticSalesChannels: 0, transactionalSalesChannels: 3 };
            const context = { showPrepareModal: true, applyReadiness: jest.fn(), loadReadinessCounts: jest.fn() };

            onSalesChannelsPrepared.call(context, summary);

            expect(context.showPrepareModal).toBe(false);
            expect(context.applyReadiness).toHaveBeenCalledWith(summary);
            expect(context.loadReadinessCounts).not.toHaveBeenCalled();
        });

        it('reloads the counts when no summary is returned', () => {
            const context = { showPrepareModal: true, applyReadiness: jest.fn(), loadReadinessCounts: jest.fn() };

            onSalesChannelsPrepared.call(context, null);

            expect(context.showPrepareModal).toBe(false);
            expect(context.applyReadiness).not.toHaveBeenCalled();
            expect(context.loadReadinessCounts).toHaveBeenCalled();
        });
    });
});
