import { expect, test } from '@fixtures/AcceptanceTest';
import { readFeedTemplates } from '@services/pluginSource';
import { feedProviders } from '../UcpContent/feedProviders';

test.describe('Feed provider coverage @Setup', () => {
    test('every shipped feed template has expectations in feedProviders.ts, and nothing else does', async () => {
        const templateProviderNames = (await readFeedTemplates()).map(template => template.providerName);

        expect(
            Object.keys(feedProviders).sort(),
            'Add an entry to tests/UcpContent/feedProviders.ts for every provider a shipped template registers.',
        ).toEqual(templateProviderNames.sort());
    });
});
