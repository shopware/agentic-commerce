/**
 * @sw-package discovery
 *
 * Turns a failed UCP admin API call (an axios error carrying a JSON:API error document) into the
 * text shown in the merchant's error notification.
 */

import { extractApiErrorMessage } from './error-message.util';

function apiError({ errors, status = 400, statusText = 'Bad Request', message = 'Request failed with status code 400' } = {}) {
    return { message, response: { status, statusText, data: errors === undefined ? {} : { errors } } };
}

describe('agentic-commerce/error-message.util', () => {
    it('joins the detail of every JSON:API error, falling back to its title', () => {
        const error = apiError({
            errors: [
                {
                    status: '400',
                    code: 'SWAG_AGENTIC_COMMERCE__UCP_CONFIG_INVALID',
                    title: 'Bad Request',
                    detail: 'Invalid UCP config at $.continueUrlTemplate: must be an absolute http(s) URL.',
                },
                { status: '400', title: 'Bad Request' },
            ],
        });

        expect(extractApiErrorMessage(error)).toBe('Invalid UCP config at $.continueUrlTemplate: must be an absolute http(s) URL.\nBad Request');
    });

    it('uses the HTTP status text when the errors carry neither detail nor title', () => {
        const error = apiError({ errors: [{ status: '500' }], status: 500, statusText: 'Internal Server Error' });

        expect(extractApiErrorMessage(error)).toBe('Internal Server Error');
    });

    it('uses the HTTP status text when the response has no JSON:API errors', () => {
        const error = apiError({ status: 503, statusText: 'Service Unavailable' });

        expect(extractApiErrorMessage(error)).toBe('Service Unavailable');
    });

    it('uses the client error message when no response arrived', () => {
        expect(extractApiErrorMessage({ message: 'Network Error' })).toBe('Network Error');
    });

    it('returns a generic message when the error carries nothing readable', () => {
        expect(extractApiErrorMessage(undefined)).toBe('Unknown UCP administration error.');
    });
});