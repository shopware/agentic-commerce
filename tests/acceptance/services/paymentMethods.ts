import { expect } from '@playwright/test';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';

const CORE_PAYMENT_HANDLER_NAMESPACE = 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\';

export async function activePaymentMethodId(
    TestDataService: FixtureTypes['TestDataService'],
    coreHandlerClass: 'InvoicePayment' | 'PrePayment' | 'CashPayment',
): Promise<string> {
    const matchingMethods = await TestDataService.AdminApiClient.post('./search-ids/payment_method', {
        data: {
            filter: [
                { type: 'equals', field: 'handlerIdentifier', value: `${CORE_PAYMENT_HANDLER_NAMESPACE}${coreHandlerClass}` },
                { type: 'equals', field: 'active', value: true },
            ],
        },
    });
    expect(matchingMethods.ok(), await matchingMethods.text()).toBeTruthy();
    const { data: [activeMethodId] } = (await matchingMethods.json()) as { data: string[] };
    expect(activeMethodId, `the shop has no active ${coreHandlerClass} payment method`).toBeDefined();

    return activeMethodId;
}

export async function paymentMethodIdsOfOrder(TestDataService: FixtureTypes['TestDataService'], orderId: string): Promise<string[]> {
    const transactions = await TestDataService.AdminApiClient.get(`./order/${orderId}/transactions`);
    expect(transactions.ok(), await transactions.text()).toBeTruthy();

    return ((await transactions.json()) as { data: { paymentMethodId: string }[] }).data.map(transaction => transaction.paymentMethodId);
}
