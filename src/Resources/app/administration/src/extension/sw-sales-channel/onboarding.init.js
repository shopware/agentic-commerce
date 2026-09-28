import { shouldOfferOnboarding, SETTINGS_ROUTE } from './agentic-commerce/onboarding-state';
import { readOnboardingDismissed, writeOnboardingDismissed } from './agentic-commerce/onboarding-dismissal';
import { isAdminLoggedIn } from './agentic-commerce/admin-session';
import { createAdminNotification } from './agentic-commerce/admin-notification';
import { translateAdmin } from './agentic-commerce/admin-i18n';

/**
 * Tells a merchant, once, that Agentic Commerce is installed and needs setting up.
 *
 * Shopware has no "plugin was just installed" event the administration can react
 * to, so this derives the same thing: nothing exposes UCP yet, and this user has
 * not dismissed the offer. It then raises a notification offering to open
 * Settings > Agentic Commerce, rather than navigating there uninvited.
 *
 * The check runs from a router `afterEach`, not from `viewInitialized`, because
 * the session's `currentUser` is still empty when the view initialises and
 * `AclService.can()` answers false for everything until it is loaded. Hooking
 * the first navigation that carries a logged-in user is what makes the ACL gate
 * meaningful; evaluating earlier silently skipped the offer forever.
 *
 * Costs nothing for a user who cannot edit UCP config, one request for one who
 * already dismissed the offer, and two while the shop is genuinely unconfigured.
 */

const SNIPPET_ROOT = 'swagAgenticCommerce.onboarding.notification';

let evaluated = false;

export function resetOnboardingOfferForTesting() {
    evaluated = false;
}

function canEditUcp() {
    return Shopware.Service('acl')?.can?.('ucp.editor') === true;
}

function currentRouteName(router) {
    // Vue Router 4 exposes a ref, Vue Router 3 the route object itself.
    return router.currentRoute?.value?.name ?? router.currentRoute?.name ?? null;
}

function fetchSalesChannels() {
    const service = Shopware.Service('ucpAdminApiService');
    if (!service?.getSalesChannels) {
        return Promise.resolve([]);
    }

    return service
        .getSalesChannels()
        .then((response) => response?.data?.data ?? [])
        .catch(() => []);
}

/**
 * Stays open until the merchant answers it: an offer that disappears on a timer
 * is one they cannot act on.
 */
export function buildOnboardingNotification() {
    return {
        variant: 'success',
        growl: true,
        autoClose: false,
        title: translateAdmin(`${SNIPPET_ROOT}.title`),
        message: translateAdmin(`${SNIPPET_ROOT}.message`),
        actions: [
            {
                label: translateAdmin(`${SNIPPET_ROOT}.setUpNow`),
                route: { name: SETTINGS_ROUTE },
            },
            {
                label: translateAdmin(`${SNIPPET_ROOT}.doItLater`),
                method: () => {
                    void writeOnboardingDismissed();
                },
            },
        ],
    };
}

export function offerOnboarding(router) {
    // Not yet logged in: stay unevaluated so the next navigation tries again.
    if (!router || evaluated || !isAdminLoggedIn()) {
        return Promise.resolve(false);
    }

    if (!canEditUcp()) {
        evaluated = true;

        return Promise.resolve(false);
    }

    evaluated = true;

    return readOnboardingDismissed()
        .then((dismissed) => {
            if (dismissed) {
                return false;
            }

            return fetchSalesChannels().then((channels) => {
                if (!shouldOfferOnboarding({
                    dismissed,
                    channels,
                    currentRouteName: currentRouteName(router),
                })) {
                    return false;
                }

                createAdminNotification(buildOnboardingNotification());

                return true;
            });
        })
        .catch(() => false);
}

export function installOnboardingHook(router) {
    if (!router || typeof router.afterEach !== 'function') {
        return false;
    }

    router.afterEach(() => {
        void offerOnboarding(router);
    });

    return true;
}

void Shopware.Application.viewInitialized.then(() => {
    installOnboardingHook(Shopware.Application.view?.router);
});