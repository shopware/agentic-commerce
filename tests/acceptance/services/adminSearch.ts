import { expect } from '@playwright/test';
import type { FixtureTypes } from '@fixtures/AcceptanceTest';

export async function searchIds(
    TestDataService: FixtureTypes['TestDataService'],
    entity: 'order' | 'customer',
    field: string,
    value: string,
): Promise<string[]> {
    const matchingEntities = await TestDataService.AdminApiClient.post(`./search-ids/${entity}`, {
        data: { filter: [{ type: 'equals', field, value }] },
    });
    expect(matchingEntities.ok(), await matchingEntities.text()).toBeTruthy();

    return ((await matchingEntities.json()) as { data: string[] }).data;
}
