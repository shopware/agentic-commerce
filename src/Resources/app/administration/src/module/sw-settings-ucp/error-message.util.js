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
