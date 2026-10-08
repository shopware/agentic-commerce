/**
 * @sw-package discovery
 *
 * The statistics tab charts the orders and customers an export channel brought in. It may only
 * query what the merchant is allowed to see, must count only orders tracked to this channel, and
 * must never leave the tab loading forever.
 */

jest.mock('./sw-sales-channel-detail-agentic-commerce-statistics.html.twig', () => 'mock-template', { virtual: true });

const SALES_CHANNEL_ID = 'agentic-channel-id';

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

    static range(field, parameters) {
        return { type: 'range', field, parameters };
    }

    static sort(field, order = 'ASC') {
        return { field, order };
    }
}

describe('sw-sales-channel-detail-agentic-commerce-statistics', () => {
    let component;

    beforeEach(() => {
        const register = jest.fn();
        global.Shopware = {
            Component: { getComponentRegistry: () => new Map(), register, override: jest.fn() },
            Data: { Criteria },
            Utils: {
                format: {
                    dateWithUserTimezone: (date = new Date()) => new Date(date),
                    toISODate: (date) => date.toISOString().slice(0, 10),
                },
            },
        };

        require('./index');

        [[, component]] = register.mock.calls;
    });

    afterEach(() => {
        jest.useRealTimers();
        jest.resetModules();
        delete global.Shopware;
    });

    function createInstance({
        privileges = [],
        orderSearch = jest.fn(() => Promise.resolve([])),
        customerSearch = jest.fn(() => Promise.resolve([])),
    } = {}) {
        const repositories = { order: { search: orderSearch }, customer: { search: customerSearch } };
        const instance = {
            ...component.data(),
            salesChannel: { id: SALES_CHANNEL_ID },
            repositoryFactory: { create: (entity) => repositories[entity] },
            acl: { can: (privilege) => privileges.includes(privilege) },
            $t: (key) => key,
        };
        Object.entries(component.methods).forEach(([name, method]) => {
            instance[name] = method.bind(instance);
        });
        Object.entries(component.computed).forEach(([name, getter]) => {
            Object.defineProperty(instance, name, { get: () => getter.call(instance) });
        });

        return instance;
    }

    it('loads order statistics only for order viewers and customer statistics only for customer viewers', async () => {
        const orderViewer = createInstance({ privileges: ['order.viewer'] });
        await orderViewer.fetchData();
        expect(orderViewer.orderRepository.search).toHaveBeenCalledTimes(2);
        expect(orderViewer.customerRepository.search).not.toHaveBeenCalled();

        const customerViewer = createInstance({ privileges: ['customer.viewer'] });
        await customerViewer.fetchData();
        expect(customerViewer.orderRepository.search).not.toHaveBeenCalled();
        expect(customerViewer.customerRepository.search).toHaveBeenCalledTimes(1);
    });

    it('charts turnover from the paid-orders query and the order count from the all-orders query', async () => {
        const paidOrders = [{ id: 'paid' }];
        const allOrders = [{ id: 'paid' }, { id: 'open' }];
        const customers = [{ id: 'customer' }];
        const isPaidQuery = (criteria) => criteria.filters.some((filter) => filter.field === 'transactions.stateMachineState.technicalName');
        const instance = createInstance({
            privileges: ['order.viewer', 'customer.viewer'],
            orderSearch: jest.fn((criteria) => Promise.resolve(isPaidQuery(criteria) ? paidOrders : allOrders)),
            customerSearch: jest.fn(() => Promise.resolve(customers)),
        });

        await instance.fetchData();

        expect(instance.historyOrderDataSum).toBe(paidOrders);
        expect(instance.historyOrderDataCount).toBe(allOrders);
        expect(instance.historyCustomerDataCount).toBe(customers);
        expect(instance.customerRepository.search).toHaveBeenCalledWith(expect.objectContaining({
            filters: expect.arrayContaining([expect.objectContaining({ type: 'range', field: 'createdAt' })]),
        }));
    });

    it('stops loading when a statistics request fails', async () => {
        const instance = createInstance({
            privileges: ['order.viewer', 'customer.viewer'],
            orderSearch: jest.fn(() => Promise.reject(new Error('Request failed'))),
        });

        await instance.fetchData();

        expect(instance.isLoading).toBe(false);
    });

    it('counts only orders and customers tracked to this sales channel, and only paid orders as turnover', () => {
        const instance = createInstance();
        const trackedToChannel = { type: 'equals', field: 'salesChannelTracking.salesChannelId', value: SALES_CHANNEL_ID };

        expect(instance.orderCountCriteria.filters).toContainEqual(trackedToChannel);
        expect(instance.customerCountCriteria.filters).toContainEqual(trackedToChannel);
        expect(instance.orderSumCriteria.filters).toEqual(expect.arrayContaining([
            trackedToChannel,
            { type: 'equals', field: 'transactions.stateMachineState.technicalName', value: 'paid' },
        ]));
        expect(instance.orderCountCriteria.filters).not.toContainEqual(expect.objectContaining({ field: 'transactions.stateMachineState.technicalName' }));
    });

    it.each([
        ['30Days', new Date(2026, 8, 7)],
        ['yesterday', new Date(2026, 9, 6)],
        ['24Hours', new Date(2026, 9, 6, 12)],
    ])('starts the %s range at the right moment', (range, expectedStart) => {
        jest.useFakeTimers({ now: new Date(2026, 9, 7, 12) });
        const instance = createInstance();

        expect(instance.dateAgoValue({ ...instance.statisticDateRangesOrderCount, value: range })).toEqual(expectedStart);
    });

    it('queries each chart from the start of its own range', () => {
        jest.useFakeTimers({ now: new Date(2026, 9, 7, 12) });
        const instance = createInstance();
        instance.statisticDateRangesOrderCount.value = '7Days';
        instance.statisticDateRangesOrderSum.value = '14Days';
        instance.statisticDateRangesCustomerCount.value = '180Days';
        const from = (date) => ({ gte: global.Shopware.Utils.format.toISODate(date) });

        expect(instance.orderCountCriteria.filters).toContainEqual({ type: 'range', field: 'orderDate', parameters: from(new Date(2026, 8, 30)) });
        expect(instance.orderSumCriteria.filters).toContainEqual({ type: 'range', field: 'orderDate', parameters: from(new Date(2026, 8, 23)) });
        expect(instance.customerCountCriteria.filters).toContainEqual({ type: 'range', field: 'createdAt', parameters: from(new Date(2026, 3, 10)) });
    });

    it('groups orders per day, and per hour for yesterday and the last 24 hours', () => {
        const instance = createInstance();
        const orders = [
            { orderDateTime: '2026-10-01T09:15:00.000+00:00' },
            { orderDateTime: '2026-10-01T09:45:00.000+00:00' },
            { orderDateTime: '2026-10-01T17:05:00.000+00:00' },
        ];
        const day = new Date('2026-10-01T00:00:00.000+00:00').getTime();
        const nineOClock = new Date('2026-10-01T09:00:00.000+00:00').getTime();
        const fiveOClock = new Date('2026-10-01T17:00:00.000+00:00').getTime();

        const perDay = instance.aggregateCount(orders, 'orderDateTime', { value: '30Days', options: { '30Days': 30 } });
        const perHour = instance.aggregateCount(orders, 'orderDateTime', { value: '24Hours', options: { '24Hours': 24 } });
        const yesterday = instance.aggregateCount(orders, 'orderDateTime', { value: 'yesterday', options: { yesterday: 1 } });

        expect(perDay).toEqual([{ x: day, y: 3 }]);
        expect(perHour).toEqual([{ x: nineOClock, y: 2 }, { x: fiveOClock, y: 1 }]);
        expect(yesterday).toEqual(perHour);
    });

    it('sums the turnover per bucket and in total', () => {
        const instance = createInstance();
        instance.historyOrderDataSum = [
            { orderDateTime: '2026-10-01T09:15:00.000+00:00', amountTotal: 19.99 },
            { orderDateTime: '2026-10-01T17:05:00.000+00:00', amountTotal: 5.01 },
            { orderDateTime: '2026-10-02T08:00:00.000+00:00', amountTotal: 100 },
        ];

        const perDay = instance.aggregateTurnover(instance.historyOrderDataSum, { value: '7Days', options: { '7Days': 7 } });

        expect(perDay).toEqual([
            { x: new Date('2026-10-01T00:00:00.000+00:00').getTime(), y: 25 },
            { x: new Date('2026-10-02T00:00:00.000+00:00').getTime(), y: 100 },
        ]);
        expect(instance.orderSumTotal).toBe(125);
    });
});