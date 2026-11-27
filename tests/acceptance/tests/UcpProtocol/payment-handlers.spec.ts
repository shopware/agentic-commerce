import { expect, test } from '@fixtures/AcceptanceTest';
import { createCompletableCheckout, invoiceInstrument, placeOrder } from '@services/completableCheckout';
import { activePaymentMethodId, paymentMethodIdsOfOrder } from '@services/paymentMethods';
import { isRefusal } from '@services/ucpMessages';

test.describe('Payment handlers in the UCP profile @UcpProtocol', () => {
    test('an activated channel advertises a payment handler an agent can complete a checkout with', async ({ TestDataService }) => {
        const activatedChannel = await TestDataService.createStorefrontSalesChannel();
        await TestDataService.activateUcp(activatedChannel.salesChannel.id);

        const activatedChannelProfilePreview = await TestDataService.getProfilePreview(activatedChannel.salesChannel.id);

        expect(Object.keys(activatedChannelProfilePreview.payment_handlers ?? {}), 'checkout.complete needs a payment instrument bound to an advertised handler').not.toHaveLength(0);
    });

    test('the profile lists exactly the handlers of the assigned payment methods', async ({ TestDataService, UcpAgent }) => {
        const invoiceMethodId = await activePaymentMethodId(TestDataService, 'InvoicePayment');
        const prepaymentMethodId = await activePaymentMethodId(TestDataService, 'PrePayment');
        const { salesChannelId } = await createCompletableCheckout(TestDataService, UcpAgent, {
            paymentMethods: [{ id: invoiceMethodId }, { id: prepaymentMethodId }],
        });

        const { payment_handlers: profileHandlers = {} } = await TestDataService.getProfilePreview(salesChannelId);

        expect(Object.values(profileHandlers).flat().map(profileHandler => profileHandler.id).sort()).toEqual(['com.shopware.invoice', 'com.shopware.prepayment']);
    });

    test('an instrument on update and on completion places the order with its payment method, not the channel default', async ({ TestDataService, UcpAgent }) => {
        const invoiceMethodId = await activePaymentMethodId(TestDataService, 'InvoicePayment');
        const channelDefaultMethodId = await activePaymentMethodId(TestDataService, 'PrePayment');
        const { agent, buyer, fulfillment, lineItems, salesChannelId, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent, {
            paymentMethodId: channelDefaultMethodId,
            paymentMethods: [{ id: invoiceMethodId }, { id: channelDefaultMethodId }],
        });

        const updatedCheckout = await agent.rest.send('PUT', checkoutPath, {
            body: { line_items: lineItems, buyer, fulfillment, payment: { instruments: [invoiceInstrument] } },
        });
        expect(updatedCheckout.status(), await updatedCheckout.text()).toBe(200);
        expect(await isRefusal(updatedCheckout), await updatedCheckout.text()).toBe(false);

        const placedOrder = await placeOrder(TestDataService, agent, salesChannelId, checkoutPath);

        expect(await paymentMethodIdsOfOrder(TestDataService, placedOrder.id)).toEqual([invoiceMethodId]);
    });
});
