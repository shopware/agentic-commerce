import template from './sw-admin-menu-item.html.twig';

const { Component } = Shopware;

// Override sw-admin-menu-item so the Agentic Commerce sales channel type
// icon renders correctly in the sidebar on Shopware 6.5. The meteor-icon-kit
// shipped with 6.5 (v5.0.0) does not include regular-sparkle; this override
// replaces the sw-icon usage for that specific icon name with an inline SVG,
// avoiding the runtime module-not-found error entirely.
Component.override('sw-admin-menu-item', { template });
