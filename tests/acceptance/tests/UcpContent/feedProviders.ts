import { parseGoogleMerchantFeed, parseJsonlFeed } from '@services/feedParsing';
import type { FeedRow } from '@services/feedParsing';
import type { UcpTestDataService } from '@services/UcpTestDataService';

/** A feed set-up whose rendered output the provider's validator must reject. */
export interface InvalidFeedSetup {
    productIds: string[]
    settings: Record<string, unknown>
    includeVariants: boolean
    rejectedField: string
}

export interface FeedProviderExpectations {
    contentType: string
    parse(body: string): FeedRow[]
    settings: Record<string, string>
    fields: {
        id: string
        title: string
        description: string
        link: string
        imageLink: string
        price: string
        availability: string
        brand: string
        weight: string
        groupId: string
        /** null where the provider leaves essential characteristics out on purpose. */
        characteristics: string | null
    }
    untrackedLinks: string[]
    breakValidation(testData: UcpTestDataService): Promise<InvalidFeedSetup>
}

export const feedProviders: Record<string, FeedProviderExpectations> = {
    'open-ai': {
        contentType: 'application/x-ndjson',
        parse: parseJsonlFeed,
        settings: { 'SwagAgenticCommerce.openAiProductExport.returnPolicyUrl': 'https://shop.example/return-policy' },
        fields: {
            id: 'item_id',
            title: 'title',
            description: 'description',
            link: 'url',
            imageLink: 'image_url',
            price: 'price',
            availability: 'availability',
            brand: 'brand',
            weight: 'weight',
            groupId: 'group_id',
            characteristics: null,
        },
        untrackedLinks: [],
        breakValidation: async testData => ({
            productIds: [(await testData.createProductWithImage()).id],
            settings: { 'SwagAgenticCommerce.openAiProductExport.returnPolicyUrl': 'not a url' },
            includeVariants: false,
            rejectedField: 'return_policy',
        }),
    },
    google: {
        contentType: 'text/xml',
        parse: parseGoogleMerchantFeed,
        settings: {},
        fields: {
            id: 'g:id',
            title: 'title',
            description: 'description',
            link: 'link',
            imageLink: 'g:image_link',
            price: 'g:price',
            availability: 'g:availability',
            brand: 'g:brand',
            weight: 'g:product_weight',
            groupId: 'g:item_group_id',
            characteristics: 'g:product_detail',
        },
        untrackedLinks: ['g:canonical_link'],
        breakValidation: async (testData) => {
            const parentProduct = await testData.createProductWithImage();
            const colourGroup = await testData.createColorPropertyGroup();
            const variantProducts = await testData.createVariantProducts(parentProduct, [colourGroup]);

            return {
                productIds: [parentProduct.id, ...variantProducts.map(variant => variant.id)],
                settings: { 'SwagAgenticCommerce.googleProductExport.variantCondition': [colourGroup.id] },
                includeVariants: true,
                rejectedField: 'condition',
            };
        },
    },
};
