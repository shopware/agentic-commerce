import { readAdminStore } from './admin-store';

/**
 * Reads the logged-in admin user, wherever this lane keeps the session store.
 *
 * An unreadable session is reported as "nobody logged in" rather than as an
 * exception; see admin-store.js for why the read has to be guarded at all.
 */

export function adminSession() {
    return readAdminStore('session');
}

export function currentAdminUserId() {
    return adminSession()?.currentUser?.id ?? null;
}

export function isAdminLoggedIn() {
    return Boolean(adminSession()?.currentUser);
}