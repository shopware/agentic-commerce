import template from './sw-settings-ucp-detail.html.twig';
import './sw-settings-ucp-detail.scss';
import { extractApiErrorMessage } from '../../error-message.util.js';
import {
    capabilityOptions,
    keyAlgorithmOptions,
    profileUriStrategyOptions,
    signaturePolicyOptions,
    transportOptions,
} from './options.js';
import {
    defaultForm,
    normalizeConfig,
    toggleArrayValue,
} from './form-state.js';

const { Mixin } = Shopware;

export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['ucpAdminApiService', 'acl'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            isSaving: false,
            isKeyActionLoading: false,
            salesChannel: null,
            meta: {},
            form: defaultForm(),
            preview: null,
            keys: [],
            newKeyKid: '',
            newKeyAlgorithm: 'ES256',
        };
    },

    created() {
        this.load();
    },

    computed: {
        salesChannelId() {
            return this.$route.params.salesChannelId;
        },

        canEditConfig() {
            return this.acl.can('ucp.editor');
        },

        canRotateKeys() {
            return this.acl.can('ucp.key_rotator');
        },

        capabilityOptions() {
            return capabilityOptions;
        },

        transportOptions() {
            return transportOptions.map((option) => ({
                ...option,
                label: option.label.includes('.') ? this.$tc(option.label) : option.label,
                description: option.description ? this.$tc(option.description) : '',
                disabled: this.isTransportUnsupported(option),
                disabledReason: option.disabledReason ? this.$tc(option.disabledReason) : '',
            }));
        },

        signaturePolicyOptions() {
            return signaturePolicyOptions.map((option) => ({
                ...option,
                label: this.$tc(option.label),
            }));
        },

        profileUriStrategyOptions() {
            return profileUriStrategyOptions.map((option) => ({
                ...option,
                label: this.$tc(option.label),
            }));
        },

        keyAlgorithmOptions() {
            return keyAlgorithmOptions;
        },

        showSignaturePolicyWarning() {
            return this.form.signaturePolicy !== 'strict';
        },

        showAllowlistWarning() {
            return this.form.remoteProfileAllowlist.length === 0 && this.form.agentAllowlist.length === 0 && this.form.platformAllowlist.length === 0;
        },

        showReadOnlyNotice() {
            return !this.canEditConfig;
        },

        showDiscoveryWarning() {
            return this.meta.supportsAgenticDiscovery === false;
        },

        showDeferredDiscoveryBudgetNotice() {
            return true;
        },

        showCustomProfileUri() {
            return this.form.profileUriStrategy === 'config';
        },

        activeStatusClass() {
            return this.form.active ? 'is--active' : 'is--inactive';
        },

        activeStatusLabel() {
            return this.form.active
                ? this.$tc('sw-settings-ucp.general.statusActive')
                : this.$tc('sw-settings-ucp.general.statusInactive');
        },

        enabledCapabilityCount() {
            return this.form.enabledCapabilities.length;
        },

        transportSummaryLabel() {
            if (this.form.enabledTransports.length === 0) {
                return this.$tc('sw-settings-ucp.general.noTransportLabel');
            }

            return this.form.enabledTransports.map((entry) => entry.toUpperCase()).join(', ');
        },

        salesChannelBaseRoute() {
            if (!this.salesChannel) {
                return null;
            }

            return { name: 'sw.sales.channel.detail.base', params: { id: this.salesChannel.id } };
        },

        profilePreviewJson() {
            if (!this.preview) {
                return '';
            }

            return JSON.stringify(this.preview, null, 2);
        },

        profileMetadata() {
            if (!this.preview) {
                return {};
            }

            return this.preview.ucp || this.preview;
        },

        profileCapabilityNames() {
            if (!this.profileMetadata.capabilities) {
                return [];
            }

            return Object.keys(this.profileMetadata.capabilities);
        },

        serviceEndpointCount() {
            if (!this.profileMetadata.services) {
                return 0;
            }

            return Object.values(this.profileMetadata.services).reduce((count, endpoints) => {
                return count + (Array.isArray(endpoints) ? endpoints.length : 0);
            }, 0);
        },

        sortedKeys() {
            return [...this.keys].sort((left, right) => {
                const leftCreatedAt = Date.parse(left.createdAt || '') || 0;
                const rightCreatedAt = Date.parse(right.createdAt || '') || 0;

                return rightCreatedAt - leftCreatedAt;
            });
        },

        signaturePolicyLabelText() {
            const option = this.signaturePolicyOptions.find((entry) => entry.value === this.form.signaturePolicy);

            return option ? option.label : (this.form.signaturePolicy || this.$tc('sw-settings-ucp.general.keyStatusUnknown'));
        },
    },

    methods: {
        async load() {
            this.isLoading = true;

            try {
                const [salesChannelResponse, configResponse, previewResponse, keysResponse] = await Promise.all([
                    this.ucpAdminApiService.getSalesChannel(this.salesChannelId),
                    this.ucpAdminApiService.getConfig(this.salesChannelId),
                    this.ucpAdminApiService.getProfilePreview(this.salesChannelId),
                    this.ucpAdminApiService.getKeys(this.salesChannelId),
                ]);

                this.meta = salesChannelResponse.data.meta || {};
                this.salesChannel = salesChannelResponse.data.data || null;
                this.form = normalizeConfig(configResponse.data.data || {});
                this.preview = previewResponse.data.data || null;
                this.keys = keysResponse.data.data || [];
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: extractApiErrorMessage(error),
                });
            } finally {
                this.isLoading = false;
            }
        },

        buildPayload() {
            return {
                ...this.form,
                customProfileUri: this.form.profileUriStrategy === 'config' ? this.form.customProfileUri : null,
                enabledCapabilities: [...this.form.enabledCapabilities],
                enabledTransports: [...this.form.enabledTransports],
                platformAllowlist: [...this.form.platformAllowlist],
                remoteProfileAllowlist: [...this.form.remoteProfileAllowlist],
                agentAllowlist: [...this.form.agentAllowlist],
                embeddedAllowedOrigins: [...this.form.embeddedAllowedOrigins],
                embeddedFrameAncestors: [...this.form.embeddedFrameAncestors],
            };
        },

        async save() {
            this.isSaving = true;

            try {
                await this.ucpAdminApiService.saveConfig(this.salesChannelId, this.buildPayload());
                await this.load();

                this.createNotificationSuccess({
                    title: this.$tc('global.default.success'),
                    message: this.$tc('sw-settings-ucp.general.configSaved'),
                });
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: extractApiErrorMessage(error),
                });
            } finally {
                this.isSaving = false;
            }
        },

        updateCapability(capability, enabled) {
            if (enabled instanceof Event) {
                return;
            }

            this.form.enabledCapabilities = toggleArrayValue(this.form.enabledCapabilities, capability, enabled);
        },

        updateTransport(transport, enabled) {
            if (enabled instanceof Event) {
                return;
            }

            const option = this.transportOptions.find((entry) => entry.value === transport);
            if (option && option.disabled) {
                return;
            }

            this.form.enabledTransports = toggleArrayValue(this.form.enabledTransports, transport, enabled);
        },

        isCapabilityEnabled(capability) {
            return this.form.enabledCapabilities.includes(capability);
        },

        isTransportEnabled(transport) {
            return this.form.enabledTransports.includes(transport);
        },

        isTransportUnsupported(transport) {
            return transport.requiresStoreApiMcp === true && this.meta.supportsStoreApiMcp !== true;
        },

        setPlatformAllowlist(values) {
            if (values instanceof Event) {
                return;
            }

            this.form.platformAllowlist = Array.isArray(values) ? values.filter((value) => !!value) : [];
        },

        setHostList(field, values) {
            if (values instanceof Event) {
                return;
            }

            this.form[field] = Array.isArray(values) ? values.filter((value) => !!value) : [];
        },

        // 6.5 (VUE3 flag off) emits `change` with the value; 6.6+ emits `update:value`.
        // Binding both keeps the form in sync across lanes; the Event guard ignores the
        // native `change` event that falls through as a listener on 6.6+, so it cannot
        // overwrite the value with a DOM Event.
        setValue(key, value) {
            if (value instanceof Event) {
                return;
            }

            this.form[key] = value;
        },

        setKeyValue(key, value) {
            if (value instanceof Event) {
                return;
            }

            this[key] = value;
        },

        async createKey() {
            this.isKeyActionLoading = true;

            try {
                await this.ucpAdminApiService.createKey(this.salesChannelId, {
                    kid: this.newKeyKid || undefined,
                    algorithm: this.newKeyAlgorithm,
                });

                this.newKeyKid = '';
                this.newKeyAlgorithm = 'ES256';
                await this.refreshKeys();

                this.createNotificationSuccess({
                    title: this.$tc('global.default.success'),
                    message: this.$tc('sw-settings-ucp.general.keyCreated'),
                });
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: extractApiErrorMessage(error),
                });
            } finally {
                this.isKeyActionLoading = false;
            }
        },

        async retireKey(kid) {
            this.isKeyActionLoading = true;

            try {
                await this.ucpAdminApiService.retireKey(this.salesChannelId, kid);
                await this.refreshKeys();

                this.createNotificationSuccess({
                    title: this.$tc('global.default.success'),
                    message: this.$tc('sw-settings-ucp.general.keyRetired'),
                });
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: extractApiErrorMessage(error),
                });
            } finally {
                this.isKeyActionLoading = false;
            }
        },

        async deleteKey(kid) {
            this.isKeyActionLoading = true;

            try {
                await this.ucpAdminApiService.deleteKey(this.salesChannelId, kid);
                await this.refreshKeys();

                this.createNotificationSuccess({
                    title: this.$tc('global.default.success'),
                    message: this.$tc('sw-settings-ucp.general.keyDeleted'),
                });
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: extractApiErrorMessage(error),
                });
            } finally {
                this.isKeyActionLoading = false;
            }
        },

        async refreshKeys() {
            const response = await this.ucpAdminApiService.getKeys(this.salesChannelId);
            this.keys = response.data.data || [];
        },

        domainUrls() {
            if (!this.salesChannel || !Array.isArray(this.salesChannel.domains) || this.salesChannel.domains.length === 0) {
                return this.$tc('sw-settings-ucp.general.noDomainLabel');
            }

            return this.salesChannel.domains
                .map((domain) => domain.url)
                .filter((url) => !!url)
                .join(', ');
        },

        formatKeyStatus(status) {
            if (!status) {
                return this.$tc('sw-settings-ucp.general.keyStatusUnknown');
            }

            return status.charAt(0).toUpperCase() + status.slice(1);
        },
    },
};
