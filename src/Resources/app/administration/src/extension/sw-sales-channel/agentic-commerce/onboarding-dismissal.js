import { ONBOARDING_DISMISSAL_KEY } from './onboarding-state';
import { currentAdminUserId } from './admin-session';

/**
 * Per-user "do not offer the onboarding dialog again", in core's `user_config`.
 *
 * Mirrors what core's own UserConfigClass does, which the plugin cannot import:
 * it is core TypeScript, and this administration bundle must stay esbuild-safe
 * (no bare imports). Every path degrades to "not dismissed" rather than
 * throwing, because a user without the `user_config` privileges should still be
 * able to use the dialog; they just do not get their dismissal remembered.
 */

function repository() {
    return Shopware.Service('repositoryFactory')?.create?.('user_config') ?? null;
}

function can(privilege) {
    return Shopware.Service('acl')?.can?.(privilege) === true;
}

export function readOnboardingDismissed() {
    const userId = currentAdminUserId();
    const repo = repository();

    if (!userId || !repo || !can('user_config:read')) {
        return Promise.resolve(false);
    }

    const criteria = new Shopware.Data.Criteria(1, 1);
    criteria.addFilter(Shopware.Data.Criteria.equals('key', ONBOARDING_DISMISSAL_KEY));
    criteria.addFilter(Shopware.Data.Criteria.equals('userId', userId));

    return repo
        .search(criteria, Shopware.Context.api)
        .then((result) => {
            const entry = result?.first?.() ?? null;

            return Array.isArray(entry?.value) ? entry.value.includes(true) : Boolean(entry?.value);
        })
        .catch(() => false);
}

export function writeOnboardingDismissed() {
    const userId = currentAdminUserId();
    const repo = repository();

    if (!userId || !repo || !can('user_config:create') || !can('user_config:update')) {
        return Promise.resolve(false);
    }

    const entity = repo.create(Shopware.Context.api);
    if (!entity) {
        return Promise.resolve(false);
    }

    Object.assign(entity, {
        userId,
        key: ONBOARDING_DISMISSAL_KEY,
        value: [true],
    });

    return repo
        .save(entity, Shopware.Context.api)
        .then(() => true)
        .catch(() => false);
}