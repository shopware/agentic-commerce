import type { APIResponse } from '@playwright/test';

export async function messageCodes(response: APIResponse): Promise<string[]> {
    const { messages = [] } = (await response.json()) as { messages?: { code?: string }[] };

    return messages.map(message => message.code ?? '');
}

/** UCP answers a protocol error with a 4xx and a business outcome, such as a validation error, with 200 and an error message. */
export async function isRefusal(response: APIResponse): Promise<boolean> {
    if (response.status() >= 400 && response.status() < 500) {
        return true;
    }
    if (response.status() !== 200) {
        return false;
    }
    const { messages = [] } = (await response.json()) as { messages?: { type?: string }[] };

    return messages.some(message => message.type === 'error');
}
