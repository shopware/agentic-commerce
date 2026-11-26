import { expect, test } from '@fixtures/AcceptanceTest';
import { createCompletableCheckout } from '@services/completableCheckout';
import { activePaymentMethodId } from '@services/paymentMethods';
import { readUcpProtocolVersion } from '@services/pluginSource';

test.describe('Payment handler negotiation @UcpKnownBlocked @UcpBlockedByD39', () => {
    test('an agent with a spec-shaped handler entry negotiates the handler by its name', async ({ TestDataService, UcpAgentWithProfile }) => {
        const version = readUcpProtocolVersion();
        const agentWithSpecShapedHandler = await UcpAgentWithProfile({
            paymentHandlers: {
                'com.shopware.invoice': [{
                    id: 'agent-invoice-handler',
                    version,
                    spec: 'https://developer.shopware.com/ucp/payment-handlers/invoice',
                    schema: 'https://developer.shopware.com/ucp/schemas/payment-handlers/invoice.json',
                }],
            },
        });

        const { agent, checkoutPath } = await createCompletableCheckout(TestDataService, agentWithSpecShapedHandler);

        const storedCheckout = await agent.rest.send('GET', checkoutPath);
        expect(storedCheckout.status(), await storedCheckout.text()).toBe(200);
        const { ucp: { payment_handlers: negotiatedHandlers = {} } } = (await storedCheckout.json()) as { ucp: { payment_handlers?: Record<string, unknown[]> } };
        expect(Object.keys(negotiatedHandlers), await storedCheckout.text()).toEqual(['com.shopware.invoice']);
    });
});

test.describe('Payment handlers in a checkout response @UcpKnownBlocked @UcpBlockedByD40', () => {
    test('a checkout response lists the handlers that the agent and the profile share', async ({ TestDataService, UcpAgent }) => {
        const invoiceMethodId = await activePaymentMethodId(TestDataService, 'InvoicePayment');
        const prepaymentMethodId = await activePaymentMethodId(TestDataService, 'PrePayment');
        const { agent, checkoutPath } = await createCompletableCheckout(TestDataService, UcpAgent, {
            paymentMethods: [{ id: invoiceMethodId }, { id: prepaymentMethodId }],
        });

        const storedCheckout = await agent.rest.send('GET', checkoutPath);
        expect(storedCheckout.status(), await storedCheckout.text()).toBe(200);
        const { ucp: { payment_handlers: responseHandlers = {} } } = (await storedCheckout.json()) as { ucp: { payment_handlers?: Record<string, unknown[]> } };

        expect(Object.keys(responseHandlers).sort(), 'the agent declares com.shopware.cash_on_delivery too, which the profile does not advertise').toEqual(['com.shopware.invoice', 'com.shopware.prepayment']);
    });
});
