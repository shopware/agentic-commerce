import templateMt from './sw-sales-channel-detail-agentic-commerce.mt.html.twig';
import templateSw from './sw-sales-channel-detail-agentic-commerce.sw.html.twig';
import './sw-sales-channel-detail-agentic-commerce.scss';
import { registerOrOverride } from '../../../../helper/register-or-override';
import { useMtComponents } from '../../agentic-commerce/admin-version';
import { toggleArrayValue, buildConfigPayload } from '../../agentic-commerce/ucp-form-state';
import {
    buildSubTabItems,
    resolveActiveSubTab,
    DEFAULT_SUB_TAB,
} from '../../agentic-commerce/ucp-sub-tabs';
import { READY_CAPABILITIES } from '../../agentic-commerce/ucp-capabilities';
import { availableTransports } from '../../agentic-commerce/ucp-options';
import { isPreviewDirty } from '../../agentic-commerce/ucp-profile-preview';
import { isTransactionalSalesChannelType } from '../../agentic-commerce/sales-channel-type.util';

const { Mixin, Defaults } = Shopware;

/**
 * Consolidated "Agentic Commerce" tab. Renders an always-visible unsaved-changes
 * banner, the UCP card (header on/off switch + Exposure/Preview sub-tabs), the
 * embedded product feed export card (agentic-commerce channels only), and the
 * core Agentic Files surface.
 *
 * The UCP form/preview live on the parent page (injected via
 * `swSalesChannelGetUcpState`) so edits survive tab switches and persist through
 * the page's global Save. Signature policy, signing keys and the advanced
 * host/delivery settings are managed via console commands (ucp:config:* /
 * ucp:signing-keys:*), not this UI. This component is the thin view layer over the pure
 * helpers in ../../agentic-commerce/*.
 */
registerOrOverride('sw-sales-channel-detail-agentic-commerce', {
    template: useMtComponents() ? templateMt : templateSw,

    inject: ['ucpAdminApiService', 'acl', 'swSalesChannelGetUcpState'],

    mixins: [Mixin.getByName('notification')],

    props: {
        salesChannel: {
            required: true,
        },
        productExport: {
            required: false,
            default: null,
        },
        productComparisonAccessUrl: {
            type: String,
            default: '',
        },
        isLoading: {
            type: Boolean,
            default: false,
        },
    },

    data() {
        return {
            activeSubTab: DEFAULT_SUB_TAB,
        };
    },

    watch: {
        // Re-render the live preview from the edited form (debounced) so it
        // reflects unsaved changes — see refreshEditedPreview / §10.4.
        form: {
            deep: true,
            handler() {
                this.scheduleEditedPreview();
            },
        },
    },

    beforeUnmount() {
        window.clearTimeout(this.previewTimer);
    },

    computed: {
        ucpState() {
            return this.swSalesChannelGetUcpState();
        },
        form() {
            return this.ucpState.form;
        },
        meta() {
            return this.ucpState.meta ?? {};
        },
        preview() {
            return this.ucpState.preview;
        },
        isUcpLoading() {
            return this.ucpState.isLoading || this.isLoading;
        },
        canEditConfig() {
            return this.acl.can('ucp.editor');
        },
        isActive() {
            return Boolean(this.form?.active);
        },
        isDirty() {
            return isPreviewDirty(this.ucpState.savedForm, this.form);
        },
        subTabItems() {
            return buildSubTabItems((key) => this.$t(key), { active: this.isActive });
        },
        resolvedSubTab() {
            return resolveActiveSubTab(this.activeSubTab, { active: this.isActive });
        },
        isAgenticCommerce() {
            return this.salesChannel?.typeId === Defaults.agenticCommerceTypeId;
        },
        isTransactionalSalesChannel() {
            return isTransactionalSalesChannelType(this.salesChannel?.typeId);
        },

        // Core ships the Agentic files management view from 6.7; embed it when
        // present. Older lanes do not have a useful Agentic files surface here.
        coreAgenticFilesAvailable() {
            return Boolean(
                Shopware?.Component?.getComponentRegistry?.().has('sw-sales-channel-detail-agentic-files'),
            );
        },
        readyCapabilities() {
            return READY_CAPABILITIES;
        },
        transportItems() {
            return availableTransports(this.meta);
        },
        profileDomainOptions() {
            const domains = Array.isArray(this.salesChannel?.domains) ? this.salesChannel.domains : [];
            return domains.map((domain) => ({ value: domain.url, label: domain.url })).filter((option) => option.value);
        },
        hasMultipleDomains() {
            return this.profileDomainOptions.length > 1;
        },
        // profileDomain is null until the merchant pins one; the profile is then
        // served from the channel's default (first) domain. Surface that effective
        // default in the select instead of an empty "Select…" — without writing it
        // to the form, so an unpinned config stays unpinned on save.
        selectedProfileDomain() {
            return this.form?.profileDomain || this.profileDomainOptions[0]?.value || '';
        },
        previewJson() {
            return this.preview ? JSON.stringify(this.preview, null, 2) : '';
        },
    },

    methods: {
        setSubTab(name) {
            this.activeSubTab = name;
        },

        // Debounce edits so we don't POST a preview on every keystroke/toggle.
        scheduleEditedPreview() {
            if (!this.salesChannel?.id) {
                return;
            }
            window.clearTimeout(this.previewTimer);
            this.previewTimer = window.setTimeout(() => this.refreshEditedPreview(), 400);
        },

        // Best-effort live preview from the edited config. On failure we keep the
        // last good preview rather than blanking it.
        refreshEditedPreview() {
            if (!this.salesChannel?.id) {
                return;
            }
            this.ucpAdminApiService
                .previewConfig(this.salesChannel.id, buildConfigPayload(this.form))
                .then((response) => {
                    this.ucpState.preview = response.data.data ?? null;
                })
                .catch(() => {});
        },

        // The on/off master switch. 6.5 emits `change` with the value, 6.6+ emits
        // `update:value`; the Event guard ignores the native event that falls
        // through so it can't clobber the boolean.
        setActive(value) {
            if (value instanceof Event) {
                return;
            }
            this.form.active = Boolean(value);
        },

        setValue(key, value) {
            if (value instanceof Event) {
                return;
            }
            this.form[key] = value;
        },

        updateCapability(capability, enabled) {
            if (enabled instanceof Event) {
                return;
            }
            this.form.enabledCapabilities = toggleArrayValue(this.form.enabledCapabilities, capability, enabled);
        },

        isCapabilityEnabled(capability) {
            return this.form.enabledCapabilities.includes(capability);
        },

        updateTransport(transport, enabled) {
            if (enabled instanceof Event) {
                return;
            }
            this.form.enabledTransports = toggleArrayValue(this.form.enabledTransports, transport, enabled);
        },

        isTransportEnabled(transport) {
            return this.form.enabledTransports.includes(transport);
        },
    },
});
