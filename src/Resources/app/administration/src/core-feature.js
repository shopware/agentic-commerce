const CORE_AGENTIC_COMPONENT = 'sw-sales-channel-detail-agentic-commerce-integration';

// True when core already ships the Agentic Commerce admin. Core registers this
// component during bootstrap, before any plugin bundle loads.
export const coreShipsAgenticCommerce = Boolean(
    Shopware?.Component?.getComponentRegistry?.().has(CORE_AGENTIC_COMPONENT),
);
