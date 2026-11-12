/**
 * Extract a human-readable message from a UCP admin API error response,
 * preferring JSON:API `errors[].detail`/`title`, then HTTP status text.
 */
export function extractApiErrorMessage(error) {
    const detailErrors = error?.response?.data?.errors;
    if (Array.isArray(detailErrors) && detailErrors.length > 0) {
        const messages = detailErrors
            .map((entry) => entry?.detail || entry?.title)
            .filter((entry) => typeof entry === 'string' && entry.length > 0);

        if (messages.length > 0) {
            return messages.join('\n');
        }
    }

    const statusText = error?.response?.statusText;
    if (typeof statusText === 'string' && statusText.length > 0) {
        return statusText;
    }

    return error?.message || 'Unknown UCP administration error.';
}

const WEBHOOK_HOST_NOT_LISTED = 'SWAG_AGENTIC_COMMERCE__UCP_CONFIG_WEBHOOK_HOST_NOT_LISTED';

/**
 * The text for a refused UCP config save. The server's text names console options, so the one
 * refusal a merchant can cause in the tab gets a message that says what to do there.
 *
 * @param {(key: string, values: Record<string, string>) => string} translate
 */
export function ucpSaveErrorMessage(error, translate) {
    const errors = error?.response?.data?.errors;
    const webhookHost = Array.isArray(errors)
        ? errors.find((entry) => entry?.code === WEBHOOK_HOST_NOT_LISTED)?.meta?.parameters?.host
        : undefined;

    return webhookHost
        ? translate('sw-sales-channel.detail.agenticCommerce.ucp.webhookHostNotListed', { host: webhookHost })
        : extractApiErrorMessage(error);
}
