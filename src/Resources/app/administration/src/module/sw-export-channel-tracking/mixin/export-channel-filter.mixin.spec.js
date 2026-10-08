/**
 * @sw-package discovery
 *
 * Adds an "export channel" filter to the order and customer lists. It may only offer Agentic
 * Commerce channels and must sit right after core's campaign-code filter.
 */

const AGENTIC_COMMERCE_TYPE_ID = '5e29f9890c4d4d519a1c7f9d5c24b7c1';

class Criteria {
    constructor(page, limit) {
        this.page = page;
        this.limit = limit;
        this.filters = [];
        this.sortings = [];
    }

    addFilter(filter) {
        this.filters.push(filter);
    }

    addSorting(sorting) {
        this.sortings.push(sorting);
    }

    static equals(field, value) {
        return { type: 'equals', field, value };
    }

    static sort(field, order = 'ASC') {
        return { field, order };
    }
}

describe('module/sw-export-channel-tracking/mixin/export-channel-filter.mixin', () => {
    let mixin;

    beforeEach(() => {
        const register = jest.fn();
        global.Shopware = {
            Data: { Criteria },
            Defaults: { agenticCommerceTypeId: AGENTIC_COMMERCE_TYPE_ID },
            Mixin: { register },
        };

        require('./export-channel-filter.mixin');

        [[, mixin]] = register.mock.calls;
    });

    afterEach(() => {
        jest.resetModules();
        delete global.Shopware;
    });

    function createInstance() {
        return {
            exportChannelOptions: [{ id: 'channel-a', name: 'ChatGPT feed' }],
            $t: (key) => key,
            filterFactory: {
                create: jest.fn((_entity, filters) => Object.entries(filters).map(([name, filter]) => ({ name, ...filter }))),
            },
        };
    }

    it('offers only Agentic Commerce sales channels, sorted by name', async () => {
        const channels = [{ id: 'channel-a', name: 'ChatGPT feed' }];
        const search = jest.fn(() => Promise.resolve(channels));
        const instance = { salesChannelRepository: { search } };

        mixin.methods.loadExportChannelOptions.call(instance);
        await search.mock.results[0].value;

        const [[criteria]] = search.mock.calls;
        expect(criteria.limit).toBe(500);
        expect(criteria.filters).toEqual([{ type: 'equals', field: 'typeId', value: AGENTIC_COMMERCE_TYPE_ID }]);
        expect(criteria.sortings).toEqual([{ field: 'name', order: 'ASC' }]);
        expect(instance.exportChannelOptions).toBe(channels);
    });

    it('filters by the tracked sales channel with the loaded options', () => {
        const instance = createInstance();

        const [filter] = mixin.methods.insertExportChannelFilter.call(instance, [], 'order', 'label.key', 'placeholder.key');

        expect(instance.filterFactory.create).toHaveBeenCalledWith('order', expect.any(Object));
        expect(filter).toMatchObject({
            name: 'export-channel-filter',
            property: 'salesChannelTracking.salesChannelId',
            type: 'multi-select-filter',
            label: 'label.key',
            placeholder: 'placeholder.key',
            valueProperty: 'id',
            labelProperty: 'name',
            options: instance.exportChannelOptions,
        });
    });

    it('places the filter right after the campaign-code filter', () => {
        const filters = [{ name: 'affiliate-code-filter' }, { name: 'campaign-code-filter' }, { name: 'tags-filter' }];

        const result = mixin.methods.insertExportChannelFilter.call(createInstance(), filters, 'customer', 'label', 'placeholder');

        expect(result.map((filter) => filter.name)).toEqual(['affiliate-code-filter', 'campaign-code-filter', 'export-channel-filter', 'tags-filter']);
    });

    it('appends the filter when there is no campaign-code filter', () => {
        const filters = [{ name: 'tags-filter' }];

        const result = mixin.methods.insertExportChannelFilter.call(createInstance(), filters, 'customer', 'label', 'placeholder');

        expect(result.map((filter) => filter.name)).toEqual(['tags-filter', 'export-channel-filter']);
    });
});