import type { APIResponse } from '@playwright/test';

export async function messageCodes(response: APIResponse): Promise<string[]> {
    const { messages = [] } = (await response.json()) as { messages?: { code?: string }[] };

    return messages.map(message => message.code ?? '');
}
