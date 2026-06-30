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
import { READY_CAPABILITIES, NOT_READY_CAPABILITIES } from '../../agentic-commerce/ucp-capabilities';
import {
    availableTransports,
    notReadyTransports,
    signaturePolicyOptions,
    keyAlgorithmOptions,
} from '../../agentic-commerce/ucp-options';
import { isPreviewDirty, profileCapabilityNames, serviceEndpointCount } from '../../agentic-commerce/ucp-profile-preview';
import { extractApiErrorMessage } from '../../agentic-commerce/error-message.util';

const { Mixin, Defaults } = Shopware;

/**
 * Consolidated "Agentic Commerce" tab. Renders an always-visible unsaved-changes
 * banner, the UCP card (header on/off switch + sub-tabs), the embedded product
 * feed export card (agentic-commerce channels only), and a pointer to the core
 * Agentic Files surface.
 *
 * The UCP form/keys/preview live on the parent page (injected via
 * `swSalesChannelGetUcpState`) so edits survive tab switches and persist through
 * the page's global Save. This component is the thin view layer over the pure
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
            isKeyActionLoading: false,
            newKeyKid: '',
            newKeyAlgorithm: 'ES256',
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
        keys() {
            return this.ucpState.keys ?? [];
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
        canRotateKeys() {
            return this.acl.can('ucp.key_rotator');
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

        // Core ships the Agentic files management view from 6.7; embed it when
        // present, otherwise fall back to a pointer (see template).
        coreAgenticFilesAvailable() {
            return Boolean(
                Shopware?.Component?.getComponentRegistry?.().has('sw-sales-channel-detail-agentic-files'),
            );
        },
        readyCapabilities() {
            return READY_CAPABILITIES;
        },
        notReadyCapabilities() {
            return NOT_READY_CAPABILITIES;
        },
        notYetAvailableTransports() {
            return notReadyTransports(this.meta);
        },
        transportItems() {
            return availableTransports(this.meta);
        },
        signaturePolicyItems() {
            return signaturePolicyOptions.map((option) => ({ value: option.value, label: this.$t(option.label) }));
        },
        keyAlgorithmItems() {
            return keyAlgorithmOptions;
        },
        profileDomainOptions() {
            const domains = Array.isArray(this.salesChannel?.domains) ? this.salesChannel.domains : [];
            return domains.map((domain) => ({ value: domain.url, label: domain.url })).filter((option) => option.value);
        },
        hasMultipleDomains() {
            return this.profileDomainOptions.length > 1;
        },
        previewJson() {
            return this.preview ? JSON.stringify(this.preview, null, 2) : '';
        },
        previewCapabilityCount() {
            return profileCapabilityNames(this.preview).length;
        },
        previewEndpointCount() {
            return serviceEndpointCount(this.preview);
        },
        sortedKeys() {
            return [...this.keys].sort((left, right) => {
                return (Date.parse(right.createdAt || '') || 0) - (Date.parse(left.createdAt || '') || 0);
            });
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

        // The on/off master switch (mt-card #headerRight). 6.5 emits `change`
        // with the value, 6.6+ emits `update:value`; the Event guard ignores the
        // native event that falls through so it can't clobber the boolean.
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

        setHostList(field, values) {
            if (values instanceof Event) {
                return;
            }
            this.form[field] = Array.isArray(values) ? values.filter((value) => !!value) : [];
        },

        // Meteor has no tag/multi-host field, so host allowlists are edited as
        // newline/comma separated text and parsed back into an array.
        hostListText(field) {
            return (this.form?.[field] ?? []).join('\n');
        },

        setHostListText(field, text) {
            if (text instanceof Event) {
                return;
            }
            this.form[field] = String(text ?? '')
                .split(/[\n,]/)
                .map((entry) => entry.trim())
                .filter((entry) => entry.length > 0);
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

        async createKey() {
            if (!this.salesChannel?.id) {
                return;
            }
            this.isKeyActionLoading = true;
            try {
                await this.ucpAdminApiService.createKey(this.salesChannel.id, {
                    kid: this.newKeyKid || undefined,
                    algorithm: this.newKeyAlgorithm,
                });
                this.newKeyKid = '';
                this.newKeyAlgorithm = 'ES256';
                await this.refreshKeys();
                this.createNotificationSuccess({ message: this.$t('sw-sales-channel.detail.agenticCommerce.ucp.keyCreated') });
            } catch (error) {
                this.createNotificationError({ message: extractApiErrorMessage(error) });
            } finally {
                this.isKeyActionLoading = false;
            }
        },

        async retireKey(kid) {
            this.isKeyActionLoading = true;
            try {
                await this.ucpAdminApiService.retireKey(this.salesChannel.id, kid);
                await this.refreshKeys();
                this.createNotificationSuccess({ message: this.$t('sw-sales-channel.detail.agenticCommerce.ucp.keyRetired') });
            } catch (error) {
                this.createNotificationError({ message: extractApiErrorMessage(error) });
            } finally {
                this.isKeyActionLoading = false;
            }
        },

        async deleteKey(kid) {
            this.isKeyActionLoading = true;
            try {
                await this.ucpAdminApiService.deleteKey(this.salesChannel.id, kid);
                await this.refreshKeys();
                this.createNotificationSuccess({ message: this.$t('sw-sales-channel.detail.agenticCommerce.ucp.keyDeleted') });
            } catch (error) {
                this.createNotificationError({ message: extractApiErrorMessage(error) });
            } finally {
                this.isKeyActionLoading = false;
            }
        },

        async refreshKeys() {
            const response = await this.ucpAdminApiService.getKeys(this.salesChannel.id);
            this.ucpState.keys = response.data.data || [];
        },
    },
});
