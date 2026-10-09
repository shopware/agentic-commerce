import { expect, test } from '@fixtures/AcceptanceTest';
import type { APIRequestContext } from '@playwright/test';
import { readFeedTemplates } from '@services/pluginSource';
import type { FeedTemplate } from '@services/pluginSource';
import type { FeedRow } from '@services/feedParsing';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';
import type { ProductFeed } from '@services/UcpTestDataService';
import { feedProviders } from './feedProviders';
import type { FeedProviderExpectations } from './feedProviders';

async function shippedTemplate(providerName: string): Promise<FeedTemplate> {
    const template = (await readFeedTemplates()).find(candidate => candidate.providerName === providerName);
    if (template === undefined) {
        throw new Error(`No shipped feed template for provider ${providerName}.`);
    }

    return template;
}

async function fetchFeed(request: APIRequestContext, feed: ProductFeed, expectations: FeedProviderExpectations): Promise<FeedRow[]> {
    const exportedFeed = await request.get(feed.exportUrl);
    const feedContent = await exportedFeed.text();

    expect(exportedFeed.status(), feedContent).toBe(200);
    expect(exportedFeed.headers()['content-type']).toContain(expectations.contentType);

    return expectations.parse(feedContent);
}

async function feedOfDescribedAndPlainProduct(
    TestDataService: FixtureTypes['TestDataService'],
    request: APIRequestContext,
    providerName: string,
): Promise<{ describedRow: FeedRow, plainRow: FeedRow, manufacturerNumber: string }> {
    const { uuid: featureSetId } = TestDataService.IdProvider.getIdPair();
    const createdFeatureSet = await TestDataService.AdminApiClient.post('./product-feature-set', {
        data: {
            id: featureSetId,
            name: `${TestDataService.namePrefix}features-${featureSetId}`,
            features: [{ type: 'product', id: null, name: 'manufacturerNumber', position: 0 }],
        },
    });
    expect(createdFeatureSet.ok(), await createdFeatureSet.text()).toBeTruthy();
    TestDataService.addCreatedRecord('product_feature_set', featureSetId);

    const manufacturerNumber = `MPN-${featureSetId.slice(0, 8)}`;
    const describedProduct = await TestDataService.createProductWithImage({ weight: 1.5, manufacturerNumber, featureSetId });
    const plainProduct = await TestDataService.createProductWithImage();
    const expectations = feedProviders[providerName];

    const feed = await TestDataService.createProductFeed(await shippedTemplate(providerName), [describedProduct.id, plainProduct.id]);
    await TestDataService.saveFeedSettings(feed.feedChannel.id, expectations.settings);
    const feedRows = await fetchFeed(request, feed, expectations);

    const describedRow = feedRows.find(row => row[expectations.fields.id] === describedProduct.productNumber);
    const plainRow = feedRows.find(row => row[expectations.fields.id] === plainProduct.productNumber);
    expect(describedRow).toBeDefined();
    expect(plainRow).toBeDefined();

    return { describedRow: describedRow as FeedRow, plainRow: plainRow as FeedRow, manufacturerNumber };
}

for (const [providerName, expectations] of Object.entries(feedProviders)) {
    const { fields } = expectations;

    test.describe(`${providerName} product feed @UcpContent`, () => {
        test('serves the stream\'s products without authentication, one row per product with a cover', async ({ TestDataService, request }) => {
            const manufacturer = await TestDataService.createBasicManufacturer();
            const listedProduct = await TestDataService.createProductWithImage({ manufacturerId: manufacturer.id });
            const productWithoutCover = await TestDataService.createBasicProduct({ manufacturerId: manufacturer.id });
            const currencyLookup = await TestDataService.AdminApiClient.get(`./currency/${TestDataService.defaultSalesChannel.currencyId}`);
            const { data: currency } = (await currencyLookup.json()) as { data: { isoCode: string } };

            const feed = await TestDataService.createProductFeed(await shippedTemplate(providerName), [listedProduct.id, productWithoutCover.id]);
            await TestDataService.saveFeedSettings(feed.feedChannel.id, expectations.settings);

            const feedRows = await fetchFeed(request, feed, expectations);

            expect(feedRows.map(row => row[fields.id])).toEqual([listedProduct.productNumber]);
            const [row] = feedRows;
            expect(row[fields.title]).toBe(listedProduct.name);
            expect(row[fields.description]).toBe(listedProduct.description);
            expect(row[fields.price]).toBe(`${listedProduct.price[0].gross.toFixed(2)} ${currency.isoCode}`);
            expect(row[fields.availability]).toBe('in_stock');
            expect(row[fields.brand]).toBe(manufacturer.name);
            expect(row[fields.imageLink]).toMatch(/^https?:\/\/.+\/media\/.+\.png/);
            expect(row[fields.link]).toContain(listedProduct.productNumber);
        });

        test('product links carry the feed channel and its tracking codes, other links none', async ({ TestDataService, request }) => {
            const listedProduct = await TestDataService.createProductWithImage();
            const trackingCodes = { affiliateCode: 'acceptance-affiliate', campaignCode: 'acceptance campaign' };

            const feed = await TestDataService.createProductFeed(await shippedTemplate(providerName), [listedProduct.id], { trackingCodes });
            await TestDataService.saveFeedSettings(feed.feedChannel.id, expectations.settings);

            const [row] = await fetchFeed(request, feed, expectations);
            const productLink = new URL(String(row[fields.link]));

            expect(productLink.searchParams.get('referringSalesChannel')).toBe(feed.feedChannel.id);
            expect(productLink.searchParams.get('affiliateCode')).toBe(trackingCodes.affiliateCode);
            expect(productLink.searchParams.get('campaignCode')).toBe(trackingCodes.campaignCode);
            for (const untrackedLink of expectations.untrackedLinks) {
                expect(new URL(String(row[untrackedLink])).search).toBe('');
            }
        });

        test('measurements render for a product that has them and stay absent otherwise', async ({ TestDataService, request }) => {
            const { describedRow, plainRow } = await feedOfDescribedAndPlainProduct(TestDataService, request, providerName);

            expect(describedRow[fields.weight]).toBeTruthy();
            expect(plainRow).not.toHaveProperty(fields.weight);
        });

        const characteristicsField = fields.characteristics;
        if (characteristicsField !== null) {
            test('essential characteristics render for a product with a feature set and stay absent otherwise', async ({ TestDataService, request }) => {
                const { describedRow, plainRow, manufacturerNumber } = await feedOfDescribedAndPlainProduct(TestDataService, request, providerName);

                expect(String(describedRow[characteristicsField])).toContain(manufacturerNumber);
                expect(plainRow).not.toHaveProperty(characteristicsField);
            });
        }

        test('includeVariants switches from parent rows to variant rows grouped by the parent', async ({ TestDataService, request }) => {
            const parentProduct = await TestDataService.createProductWithImage();
            const colourGroup = await TestDataService.createColorPropertyGroup();
            const variantProducts = await TestDataService.createVariantProducts(parentProduct, [colourGroup]);
            const streamProductIds = [parentProduct.id, ...variantProducts.map(variant => variant.id)];
            const template = await shippedTemplate(providerName);

            const parentFeed = await TestDataService.createProductFeed(template, streamProductIds, { includeVariants: false });
            await TestDataService.saveFeedSettings(parentFeed.feedChannel.id, expectations.settings);
            const parentRows = await fetchFeed(request, parentFeed, expectations);
            expect(parentRows.map(row => row[fields.id])).toEqual([parentProduct.productNumber]);

            const variantFeed = await TestDataService.createProductFeed(template, streamProductIds, { includeVariants: true });
            await TestDataService.saveFeedSettings(variantFeed.feedChannel.id, expectations.settings);
            const variantRows = await fetchFeed(request, variantFeed, expectations);
            expect(variantRows.map(row => row[fields.id]).sort()).toEqual(variantProducts.map(variant => variant.productNumber).sort());
            for (const variantRow of variantRows) {
                expect(variantRow[fields.groupId]).toBe(parentProduct.id);
            }
        });
    });
}

test.describe('Comparison product feed @UcpContent', () => {
    const coreStyleTemplate: FeedTemplate = {
        name: 'acceptance-comparison',
        providerName: '',
        headerTemplate: 'id,link,context',
        bodyTemplate: '{{ product.productNumber }},{{ seoUrl(\'frontend.detail.page\', {\'productId\': product.id}) }},{{ provider is defined ? \'agentic\' : \'plain\' }}',
        footerTemplate: '',
        encoding: 'UTF-8',
        fileFormat: 'csv',
    };

    test('a comparison channel gets neither the provider context nor tracking parameters', async ({ TestDataService, request }) => {
        const listedProduct = await TestDataService.createProductWithImage();

        const feed = await TestDataService.createProductFeed(coreStyleTemplate, [listedProduct.id], { asComparison: true });

        const exportedFeed = await request.get(feed.exportUrl);
        const feedContent = await exportedFeed.text();

        expect(exportedFeed.status(), feedContent).toBe(200);
        const [, comparisonRow] = feedContent.trim().split('\n');
        const [productNumber, productLink, context] = comparisonRow.split(',');
        expect(productNumber).toBe(listedProduct.productNumber);
        expect(new URL(productLink).search).toBe('');
        expect(context).toBe('plain');
    });
});
