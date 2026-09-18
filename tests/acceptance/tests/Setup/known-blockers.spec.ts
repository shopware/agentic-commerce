import { expect, test } from '@fixtures/AcceptanceTest';
import { knownBlockers } from '../../known-blockers';

test.describe('Known blockers registry @Setup', () => {
    test('every entry names an issue and an owner', () => {
        for (const blocker of knownBlockers) {
            expect(blocker.key, 'a known blocker needs a key').not.toEqual('');
            expect(blocker.owner, `known blocker ${blocker.key} needs an owner`).not.toEqual('');
            expect(blocker.issueUrl, `known blocker ${blocker.key} needs an issue URL`)
                .toMatch(/^https:\/\/github\.com\/[^/]+\/[^/]+\/(issues|pull)\/\d+$/);
        }
    });

    test('no entry is past its review date', () => {
        const today = new Date().toISOString().slice(0, 10);

        for (const blocker of knownBlockers) {
            expect(blocker.reviewBy, `known blocker ${blocker.key} needs an ISO review date`)
                .toMatch(/^\d{4}-\d{2}-\d{2}$/);
            expect(
                blocker.reviewBy >= today,
                `known blocker ${blocker.key} was due for review on ${blocker.reviewBy}. Renew it or remove it, do not let it age silently.`,
            ).toBe(true);
        }
    });

    test('keys are unique', () => {
        const keys = knownBlockers.map(blocker => blocker.key);

        expect(new Set(keys).size, 'known blocker keys must be unique').toBe(keys.length);
    });
});
