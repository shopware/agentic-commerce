import { test as base } from '@playwright/test';
import { UcpConsole } from '@services/UcpConsole';

export interface UcpConsoleTypes {
    UcpConsole: UcpConsole
}

export const test = base.extend<NonNullable<unknown>, UcpConsoleTypes>({
    UcpConsole: [
        async ({}, use) => {
            await use(UcpConsole.fromEnvironment());
        },
        { scope: 'worker' },
    ],
});
