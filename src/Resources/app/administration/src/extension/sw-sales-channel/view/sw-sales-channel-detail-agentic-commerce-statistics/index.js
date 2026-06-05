import template from './sw-sales-channel-detail-agentic-commerce-statistics.html.twig';
import './sw-sales-channel-detail-agentic-commerce-statistics.scss';
import { registerOrOverride } from '../../../../helper/register-or-override';

const { Criteria } = Shopware.Data;

const DATE_RANGE_OPTIONS = {
    '180Days': 180,
    '30Days': 30,
    '14Days': 14,
    '7Days': 7,
    '24Hours': 24,
    yesterday: 1,
};

registerOrOverride('sw-sales-channel-detail-agentic-commerce-statistics', {
    template,

    inject: [
        'repositoryFactory',
        'acl',
    ],

    props: {
        salesChannel: {
            required: true,
        },
    },

    data() {
        return {
            historyOrderDataCount: [],
            historyOrderDataSum: [],
            historyCustomerDataCount: [],
            statisticDateRangesOrderCount: { value: '30Days', options: DATE_RANGE_OPTIONS },
            statisticDateRangesOrderSum: { value: '30Days', options: DATE_RANGE_OPTIONS },
            statisticDateRangesCustomerCount: { value: '30Days', options: DATE_RANGE_OPTIONS },
            isLoading: true,
        };
    },

    computed: {
        orderRepository() {
            return this.repositoryFactory.create('order');
        },

        customerRepository() {
            return this.repositoryFactory.create('customer');
        },

        currencyFilter() {
            return Shopware.Filter.getByName('currency');
        },

        systemCurrencyISOCode() {
            return Shopware.Context.app.systemCurrencyISOCode;
        },

        today() {
            const today = Shopware.Utils.format.dateWithUserTimezone();
            today.setHours(0, 0, 0, 0);
            return today;
        },

        orderCountCriteria() {
            const criteria = new Criteria(1, 500);

            criteria.addFilter(Criteria.equals('salesChannelTracking.salesChannelId', this.salesChannel.id));
            criteria.addFilter(Criteria.range('orderDate', {
                gte: this.formatDate(this.dateAgoValue(this.statisticDateRangesOrderCount)),
            }));
            criteria.addSorting(Criteria.sort('orderDateTime', 'DESC'));

            return criteria;
        },

        orderSumCriteria() {
            const criteria = new Criteria(1, 500);

            criteria.addFilter(Criteria.equals('salesChannelTracking.salesChannelId', this.salesChannel.id));
            criteria.addFilter(Criteria.equals('transactions.stateMachineState.technicalName', 'paid'));
            criteria.addFilter(Criteria.range('orderDate', {
                gte: this.formatDate(this.dateAgoValue(this.statisticDateRangesOrderSum)),
            }));
            criteria.addSorting(Criteria.sort('orderDateTime', 'DESC'));

            return criteria;
        },

        customerCountCriteria() {
            const criteria = new Criteria(1, 500);

            criteria.addFilter(Criteria.equals('salesChannelTracking.salesChannelId', this.salesChannel.id));
            criteria.addFilter(Criteria.range('createdAt', {
                gte: this.formatDate(this.dateAgoValue(this.statisticDateRangesCustomerCount)),
            }));
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

            return criteria;
        },

        chartOptionsOrderCount() {
            return this.buildCountChartOptions(this.statisticDateRangesOrderCount);
        },

        chartOptionsCustomerCount() {
            return this.buildCountChartOptions(this.statisticDateRangesCustomerCount);
        },

        chartOptionsOrderSum() {
            return {
                xaxis: {
                    type: 'datetime',
                    min: this.dateAgoValue(this.statisticDateRangesOrderSum).getTime(),
                    labels: { datetimeUTC: false },
                },
                yaxis: {
                    min: 0,
                    tickAmount: 5,
                    labels: {
                        formatter: (value) => this.currencyFilter(value, this.systemCurrencyISOCode, 2),
                    },
                },
                tooltip: {
                    x: { format: this._tooltipFormat(this.statisticDateRangesOrderSum) },
                },
            };
        },

        orderCountSeries() {
            const data = this.aggregateCount(this.historyOrderDataCount, 'orderDateTime', this.statisticDateRangesOrderCount);
            return data.length === 0
                ? []
                : [{ name: this.$t('sw-sales-channel.detail.productExport.insights.numbers'), data }];
        },

        customerCountSeries() {
            const data = this.aggregateCount(this.historyCustomerDataCount, 'createdAt', this.statisticDateRangesCustomerCount);
            return data.length === 0
                ? []
                : [{ name: this.$t('sw-sales-channel.detail.productExport.insights.numbers'), data }];
        },

        orderSumSeries() {
            const data = this.aggregateTurnover(this.historyOrderDataSum, this.statisticDateRangesOrderSum);
            return data.length === 0
                ? []
                : [{ name: this.$t('sw-sales-channel.detail.productExport.insights.totalTurnover'), data }];
        },

        orderCountTotal() {
            return this.historyOrderDataCount.length;
        },

        customerCountTotal() {
            return this.historyCustomerDataCount.length;
        },

        orderSumTotal() {
            return this.historyOrderDataSum.reduce((sum, order) => sum + (order.amountTotal ?? 0), 0);
        },
    },

    created() {
        this.fetchData();
    },

    methods: {
        async fetchData() {
            this.isLoading = true;

            const promises = [];

            if (this.acl.can('order.viewer')) {
                promises.push(this.loadHistoryOrderCount(), this.loadHistoryOrderSum());
            }

            if (this.acl.can('customer.viewer')) {
                promises.push(this.loadHistoryCustomerCount());
            }

            try {
                await Promise.allSettled(promises);
            } finally {
                this.isLoading = false;
            }
        },

        loadHistoryOrderCount() {
            return this.orderRepository.search(this.orderCountCriteria).then((response) => {
                this.historyOrderDataCount = response;
            });
        },

        loadHistoryOrderSum() {
            return this.orderRepository.search(this.orderSumCriteria).then((response) => {
                this.historyOrderDataSum = response;
            });
        },

        loadHistoryCustomerCount() {
            return this.customerRepository.search(this.customerCountCriteria).then((response) => {
                this.historyCustomerDataCount = response;
            });
        },

        onOrderCountRangeUpdate(value) {
            this.statisticDateRangesOrderCount.value = value;
            this.loadHistoryOrderCount();
        },

        onOrderSumRangeUpdate(value) {
            this.statisticDateRangesOrderSum.value = value;
            this.loadHistoryOrderSum();
        },

        onCustomerCountRangeUpdate(value) {
            this.statisticDateRangesCustomerCount.value = value;
            this.loadHistoryCustomerCount();
        },

        buildCountChartOptions(range) {
            return {
                xaxis: {
                    type: 'datetime',
                    min: this.dateAgoValue(range).getTime(),
                    labels: { datetimeUTC: false },
                },
                yaxis: {
                    min: 0,
                    tickAmount: 3,
                    labels: {
                        formatter: (value) => parseInt(value, 10),
                    },
                },
                tooltip: {
                    x: { format: this._tooltipFormat(range) },
                },
            };
        },

        aggregateCount(rows, dateField, range) {
            const groupByHour = this.getTimeUnitInterval(range) === 'hour';
            const buckets = rows.reduce((acc, row) => this._bucketRow(acc, row[dateField], groupByHour, row), {});

            return Object.entries(buckets).map(([key, list]) => ({ x: parseInt(key, 10), y: list.length }));
        },

        aggregateTurnover(rows, range) {
            const groupByHour = this.getTimeUnitInterval(range) === 'hour';
            const buckets = rows.reduce((acc, row) => this._bucketRow(acc, row.orderDateTime, groupByHour, row), {});

            return Object.entries(buckets).map(([key, list]) => ({
                x: parseInt(key, 10),
                y: list.reduce((sum, order) => sum + (order.amountTotal ?? 0), 0),
            }));
        },

        dateAgoValue(range) {
            const date = Shopware.Utils.format.dateWithUserTimezone();
            const days = range.options[range.value] ?? 0;

            if (range.value === '24Hours') {
                date.setHours(date.getHours() - days);
                return date;
            }

            date.setDate(date.getDate() - days);
            date.setHours(0, 0, 0, 0);

            return date;
        },

        getTimeUnitInterval(range) {
            return range.value === 'yesterday' || range.value === '24Hours' ? 'hour' : 'day';
        },

        getChartRangeSubtitle(range) {
            return `${this.formatChartHeadlineDate(this.dateAgoValue(range))}-${this.formatChartHeadlineDate(this.today)}`;
        },

        formatDate(date) {
            return Shopware.Utils.format.toISODate(date, false);
        },

        formatChartHeadlineDate(date) {
            const lang = Shopware.Application.getContainer('factory').locale.getLastKnownLocale();
            return date.toLocaleDateString(lang, { day: 'numeric', month: 'short' });
        },

        _bucketRow(acc, dateString, groupByHour, row) {
            const bucketKey = this._bucketKey(dateString, groupByHour);

            if (bucketKey === null) {
                return acc;
            }

            if (!acc[bucketKey]) {
                acc[bucketKey] = [];
            }
            acc[bucketKey].push(row);

            return acc;
        },

        _bucketKey(dateString, groupByHour) {
            if (!dateString) {
                return null;
            }

            const match = dateString.match(/^(?<date>\d{4}-\d{2}-\d{2})T(?<hour>\d{2}):(?<minSec>\d{2}:\d{2})(?:\.(?<ms>\d{1,3}))?(?<trail>.*)$/);
            if (match === null) {
                return null;
            }

            const normalised = groupByHour
                ? dateString.replace(match[0], `${match.groups.date}T${match.groups.hour}:00:00.000${match.groups.trail}`)
                : dateString.replace(match[0], `${match.groups.date}T00:00:00.000${match.groups.trail}`);

            return Shopware.Utils.format.dateWithUserTimezone(new Date(normalised)).getTime();
        },

        _tooltipFormat(range) {
            return this.getTimeUnitInterval(range) === 'hour' ? 'dd MMM HH:mm' : 'dd MMM';
        },
    },
});