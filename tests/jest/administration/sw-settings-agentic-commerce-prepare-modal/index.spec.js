class FakeCriteria {
    constructor(page, limit) {
        this.page = page;
        this.limit = limit;
        this.filters = [];
    }

    addFilter(filter) {
        this.filters.push(filter);
    }

    static equalsAny(field, value) {
        return { type: 'equalsAny', field, value };
    }
}

class FakeEntityCollection {
    constructor(source, entity) {
        this.source = source;
        this.entity = entity;
        this.ids = [];
    }

    getIds() {
        return this.ids;
    }
}

global.Shopware = {
    Component: { register: jest.fn() },
    Mixin: { getByName: jest.fn(() => ({})) },
    Service: jest.fn(),
    Context: { api: {} },
    Data: { Criteria: FakeCriteria, EntityCollection: FakeEntityCollection },
};

const { swSettingsAgenticCommercePrepareModal } = require('Resources/extension/sw-settings-agentic-commerce/prepare-modal');

const { salesChannelCriteria, hasUnpreparedSalesChannels, selectedIds, hasSelection } =
    swSettingsAgenticCommercePrepareModal.computed;
const { loadUnpreparedSalesChannels, buildSelectedSalesChannelsCollection, onCancel, onConfirm } =
    swSettingsAgenticCommercePrepareModal.methods;

function baseContext(overrides = {}) {
    return {
        unpreparedIds: [],
        selectedSalesChannelsCollection: null,
        isLoading: false,
        isPreparing: false,
        $t: (key) => key,
        createNotificationSuccess: jest.fn(),
        createNotificationError: jest.fn(),
        $emit: jest.fn(),
        ...overrides,
    };
}

describe('sw-settings-agentic-commerce-prepare-modal', () => {
    beforeEach(() => {
        Shopware.Service.mockReset();
    });

    describe('loading the unprepared sales channels', () => {
        it('keeps only the ids of channels without UCP active and builds the selection', async () => {
            const getSalesChannels = jest.fn(() =>
                Promise.resolve({
                    data: {
                        data: [
                            { id: 'sc-1', ucp: { active: false } },
                            { id: 'sc-2', ucp: { active: true } },
                            { id: 'sc-3', ucp: { active: false } },
                        ],
                    },
                }),
            );
            Shopware.Service.mockReturnValue({ getSalesChannels });

            const context = baseContext({ buildSelectedSalesChannelsCollection: jest.fn(() => Promise.resolve()) });
            loadUnpreparedSalesChannels.call(context);
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(context.unpreparedIds).toEqual(['sc-1', 'sc-3']);
            expect(context.buildSelectedSalesChannelsCollection).toHaveBeenCalled();
            expect(context.isLoading).toBe(false);
        });
    });

    describe('salesChannelCriteria', () => {
        it('restricts the dropdown to the unprepared ids', () => {
            const criteria = salesChannelCriteria.call({ unpreparedIds: ['sc-1', 'sc-2'] });

            expect(criteria.filters).toEqual([{ type: 'equalsAny', field: 'id', value: ['sc-1', 'sc-2'] }]);
        });
    });

    describe('building the pre-selected collection', () => {
        it('pre-selects all unprepared channels via the repository', async () => {
            const collection = new FakeEntityCollection('/sales-channel', 'sales_channel');
            collection.ids = ['sc-1', 'sc-3'];
            const search = jest.fn(() => Promise.resolve(collection));

            const context = baseContext({
                unpreparedIds: ['sc-1', 'sc-3'],
                salesChannelRepository: { search },
                salesChannelCriteria: new FakeCriteria(1, 25),
            });

            await buildSelectedSalesChannelsCollection.call(context);

            expect(search).toHaveBeenCalledWith(context.salesChannelCriteria, Shopware.Context.api);
            expect(context.selectedSalesChannelsCollection).toBe(collection);
        });

        it('uses an empty collection when nothing is unprepared', async () => {
            const search = jest.fn();
            const context = baseContext({ unpreparedIds: [], salesChannelRepository: { search } });

            await buildSelectedSalesChannelsCollection.call(context);

            expect(search).not.toHaveBeenCalled();
            expect(context.selectedSalesChannelsCollection).toBeInstanceOf(FakeEntityCollection);
        });
    });

    describe('selection computeds', () => {
        it('reads selectedIds from the collection', () => {
            const collection = new FakeEntityCollection('/sales-channel', 'sales_channel');
            collection.ids = ['sc-1'];

            expect(selectedIds.call({ selectedSalesChannelsCollection: collection })).toEqual(['sc-1']);
            expect(selectedIds.call({ selectedSalesChannelsCollection: null })).toEqual([]);
        });

        it('computes hasUnpreparedSalesChannels and hasSelection', () => {
            expect(hasUnpreparedSalesChannels.call({ unpreparedIds: ['sc-1'] })).toBe(true);
            expect(hasUnpreparedSalesChannels.call({ unpreparedIds: [] })).toBe(false);
            expect(hasSelection.call({ selectedIds: ['sc-1'] })).toBe(true);
            expect(hasSelection.call({ selectedIds: [] })).toBe(false);
        });
    });

    describe('confirm / cancel', () => {
        it('emits close on cancel', () => {
            const context = baseContext();
            onCancel.call(context);
            expect(context.$emit).toHaveBeenCalledWith('close');
        });

        it('prepares the selected channels and emits the returned summary', async () => {
            const summary = { preparedSalesChannels: 2, agenticSalesChannels: 0, transactionalSalesChannels: 2 };
            const prepareSalesChannels = jest.fn(() => Promise.resolve({ data: { data: summary } }));
            Shopware.Service.mockReturnValue({ prepareSalesChannels });

            const context = baseContext({ hasSelection: true, selectedIds: ['sc-1', 'sc-2'] });
            onConfirm.call(context);
            await Promise.resolve();
            await Promise.resolve();

            expect(prepareSalesChannels).toHaveBeenCalledWith(['sc-1', 'sc-2']);
            expect(context.createNotificationSuccess).toHaveBeenCalled();
            expect(context.$emit).toHaveBeenCalledWith('prepared', summary);
            expect(context.isPreparing).toBe(false);
        });

        it('does nothing without a selection', () => {
            const prepareSalesChannels = jest.fn();
            Shopware.Service.mockReturnValue({ prepareSalesChannels });

            const context = baseContext({ hasSelection: false, selectedIds: [] });
            onConfirm.call(context);

            expect(prepareSalesChannels).not.toHaveBeenCalled();
            expect(context.$emit).not.toHaveBeenCalled();
        });

        it('notifies and resets the loading state on failure', async () => {
            const prepareSalesChannels = jest.fn(() => Promise.reject(new Error('boom')));
            Shopware.Service.mockReturnValue({ prepareSalesChannels });

            const context = baseContext({ hasSelection: true, selectedIds: ['sc-1'] });
            onConfirm.call(context);
            await Promise.resolve();
            await Promise.resolve();

            expect(context.createNotificationError).toHaveBeenCalled();
            expect(context.$emit).not.toHaveBeenCalledWith('prepared', expect.anything());
            expect(context.isPreparing).toBe(false);
        });
    });
});
