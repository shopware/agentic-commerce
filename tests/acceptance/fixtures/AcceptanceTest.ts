import { test as ShopwareTestSuite, mergeTests } from '@shopware-ag/acceptance-test-suite';

export * from '@shopware-ag/acceptance-test-suite';

export const test = mergeTests(ShopwareTestSuite);
