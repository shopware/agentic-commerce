export function formatShopwareVersion(version) {
    if (!version) {
        return 'n/a';
    }

    if (version.includes('9999999')) {
        const majorMinorMatch = version.match(/^(\d+\.\d+)/);

        return `${majorMinorMatch ? majorMinorMatch[1] : version}-dev`;
    }

    if (version.length > 18) {
        return `${version.slice(0, 18)}…`;
    }

    return version;
}
