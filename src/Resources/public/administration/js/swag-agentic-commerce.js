(()=>{var g=(e,t,a)=>()=>{if(a)throw a[0];try{return e&&(t=e(e=0)),t}catch(i){throw a=[i],i}};var I,oe=g(()=>{({Criteria:I}=Shopware.Data);Shopware.Mixin.register("export-channel-filter",{inject:["repositoryFactory","filterFactory"],data(){return{exportChannelOptions:[]}},computed:{salesChannelRepository(){return this.repositoryFactory.create("sales_channel")}},methods:{loadExportChannelOptions(){let e=new I(1,500);e.addFilter(I.equals("typeId",Shopware.Defaults.agenticCommerceTypeId)),e.addSorting(I.sort("name")),this.salesChannelRepository.search(e).then(t=>{this.exportChannelOptions=t})},insertExportChannelFilter(e,t,a,i){let n=this.filterFactory.create(t,{"export-channel-filter":{property:"salesChannelTracking.salesChannelId",type:"multi-select-filter",label:this.$t(a),placeholder:this.$t(i),valueProperty:"id",labelProperty:"name",options:this.exportChannelOptions}}).pop(),s=e.findIndex(r=>r.name==="campaign-code-filter");return s!==-1?e.splice(s+1,0,n):e.push(n),e}}})});var Qe,le=g(()=>{({Component:Qe}=Shopware);Qe.override("sw-order-list",{mixins:[Shopware.Mixin.getByName("export-channel-filter")],computed:{orderCriteria(){let e=this.$super("orderCriteria");return e.addAssociation("salesChannelTracking.salesChannel"),e},listFilters(){let e=this.$super("listFilters");return this.insertExportChannelFilter(e,"order","sw-export-channel-tracking.general.exportChannelLabel","sw-export-channel-tracking.general.exportChannelFilterPlaceholder")}},methods:{createdComponent(){return this.defaultFilters.push("export-channel-filter"),this.loadExportChannelOptions(),this.$super("createdComponent")},getOrderColumns(){let e=this.$super("getOrderColumns");return e.push({property:"extensions.salesChannelTracking.salesChannel.name",dataIndex:"extensions.salesChannelTracking.salesChannelId",label:"sw-export-channel-tracking.general.exportChannelLabel",allowResize:!0,visible:!1}),e}}})});var Ze,ce=g(()=>{({Component:Ze}=Shopware);Ze.override("sw-customer-list",{mixins:[Shopware.Mixin.getByName("export-channel-filter")],computed:{defaultCriteria(){let e=this.$super("defaultCriteria");return e.addAssociation("salesChannelTracking.salesChannel"),e},listFilters(){let e=this.$super("listFilters");return this.insertExportChannelFilter(e,"customer","sw-export-channel-tracking.general.exportChannelLabel","sw-export-channel-tracking.general.exportChannelFilterPlaceholder")}},methods:{createdComponent(){return this.defaultFilters.push("export-channel-filter"),this.loadExportChannelOptions(),this.$super("createdComponent")},getCustomerColumns(){let e=this.$super("getCustomerColumns");return e.push({property:"extensions.salesChannelTracking.salesChannel.name",dataIndex:"extensions.salesChannelTracking.salesChannelId",label:"sw-export-channel-tracking.general.exportChannelLabel",allowResize:!0,visible:!1}),e}}})});var et={};var de=g(()=>{oe();le();ce();Shopware.Module.register("sw-export-channel-tracking",{type:"core",name:"export-channel-tracking",title:"sw-export-channel-tracking.general.mainMenuItemGeneral",description:"sw-export-channel-tracking.general.descriptionTextModule"})});var ue="sw-sales-channel-detail-agentic-commerce-integration",c=!!Shopware?.Component?.getComponentRegistry?.().has(ue);Shopware.Defaults.agenticCommerceTypeId||(Shopware.Defaults.agenticCommerceTypeId="5e29f9890c4d4d519a1c7f9d5c24b7c1");var ge=Shopware.Classes.ApiService,{Application:O}=Shopware,b=class extends ge{constructor(t,a,i="ucp"){super(t,a,i)}getSalesChannels(){return this.httpClient.get("/_admin/ucp/sales-channels",this.options())}getSalesChannel(t){return this.httpClient.get(this.basePath(t),this.options())}getConfig(t){return this.httpClient.get(this.basePath(t,"/config"),this.options())}saveConfig(t,a){return this.httpClient.put(this.basePath(t,"/config"),a,this.options())}getProfilePreview(t){return this.httpClient.get(this.basePath(t,"/profile-preview"),this.options())}previewConfig(t,a){return this.httpClient.post(this.basePath(t,"/profile-preview"),a,this.options())}basePath(t,a=""){return`/_admin/ucp/sales-channels/${encodeURIComponent(t)}${a}`}options(){return{headers:this.getBasicHeaders()}}};O.addServiceProvider("ucpAdminApiService",()=>{let e=O.getContainer("init");return new b(e.httpClient,Shopware.Service("loginService"))});Shopware.Service("privileges").addPrivilegeMappingEntry({category:"permissions",parent:null,key:"customer",roles:{viewer:{privileges:["sales_channel_tracking_customer:read"],dependencies:[]}}});Shopware.Service("privileges").addPrivilegeMappingEntry({category:"permissions",parent:null,key:"sales_channel",roles:{viewer:{privileges:["system_config:read","sales_channel_tracking_order:read","sales_channel_tracking_customer:read","order:read","order_transaction:read","state_machine_state:read"],dependencies:[]},editor:{privileges:["system_config:update","system_config:create","system_config:delete","property_group:read"],dependencies:[]},creator:{privileges:["property_group:read"],dependencies:[]}}});Shopware.Service("privileges").addPrivilegeMappingEntry({category:"permissions",parent:null,key:"ucp",roles:{viewer:{privileges:["system_config:read","sales_channel:read","sales_channel_domain:read"],dependencies:[]},editor:{privileges:["system_config:update"],dependencies:["ucp.viewer"]},key_rotator:{privileges:[],dependencies:["ucp.viewer"]}}});var P=`{% set title = product.translated.name|default(product.name)|default('')|trim %}
{% set description = product.translated.description|default(title)|default('')|striptags|trim %}
{% set price = product.calculatedPrice %}
{% if product.calculatedPrices.count > 0 %}
    {% set price = product.calculatedPrices.last %}
{% endif %}
{% set unitPrice = price.unitPrice %}
{% set regularPriceValue = unitPrice %}
{% set salePriceValue = null %}
{% if price.listPrice is defined and price.listPrice %}
    {% set regularPriceValue = price.listPrice.price %}
    {% if price.listPrice.price > unitPrice %}
        {% set salePriceValue = unitPrice %}
    {% endif %}
{% endif %}
{% set imageUrl = '' %}
{% if product.cover is defined and product.cover and product.cover.media is defined and product.cover.media %}
    {% set imageUrl = product.cover.media.url %}
{% endif %}
{% set additionalImageUrls = [] %}
{% if product.media is defined and product.media %}
    {% for productMedia in product.media %}
        {% if productMedia.media is defined and productMedia.media and productMedia.media.url and productMedia.id != product.coverId %}
            {% set additionalImageUrls = additionalImageUrls|merge([productMedia.media.url]) %}
        {% endif %}
    {% endfor %}
{% endif %}
{% set hasVariants = product.parentId or product.childCount > 0 %}
{% set isConcreteVariant = product.parentId %}
{% set productUrl = seoUrl('frontend.detail.page', {'productId': product.id}) ~ '?referringSalesChannel=' ~ provider.referringSalesChannel %}
{% if provider.affiliateCode %}
    {% set productUrl = productUrl ~ '&affiliateCode=' ~ provider.affiliateCode|url_encode %}
{% endif %}
{% if provider.campaignCode %}
    {% set productUrl = productUrl ~ '&campaignCode=' ~ provider.campaignCode|url_encode %}
{% endif %}
{% set feedRow = {
    'is_eligible_search': provider.isEligibleSearch,
    'is_eligible_checkout': provider.isEligibleCheckout,
    'item_id': product.productNumber ? product.productNumber : product.id,
    'title': title,
    'description': description,
    'url': productUrl,
    'image_url': imageUrl,
    'price': (regularPriceValue|number_format(context.currency.itemRounding.decimals, '.', '')) ~ ' ' ~ context.currency.isoCode,
    'availability': product.available ? 'in_stock' : (product.restockTime ? 'backorder' : 'out_of_stock'),
    'brand': (product.manufacturer is defined and product.manufacturer) ? product.manufacturer.translated.name : provider.sellerName,
    'seller_name': provider.sellerName,
    'seller_url': provider.sellerUrl,
    'return_policy': provider.returnPolicyUrl,
    'store_country': provider.storeCountry,
    'gtin': product.ean|default(''),
    'mpn': product.manufacturerNumber|default(''),
    'is_digital': product.downloads is defined and product.downloads|length > 0
} %}
{% if provider.targetCountries is not empty %}
    {% set feedRow = feedRow|merge({
        'target_countries': provider.targetCountries
    }) %}
{% endif %}
{% if additionalImageUrls is not empty %}
    {% set feedRow = feedRow|merge({
        'additional_image_urls': additionalImageUrls|join(',')
    }) %}
{% endif %}
{% if salePriceValue is not null %}
    {% set feedRow = feedRow|merge({
        'sale_price': (salePriceValue|number_format(context.currency.itemRounding.decimals, '.', '')) ~ ' ' ~ context.currency.isoCode
    }) %}
{% endif %}
{% set feedRow = feedRow|merge({
    'listing_has_variations': hasVariants
}) %}
{% if hasVariants %}
    {% set offerId = 'SKU-' ~ (product.productNumber ? product.productNumber : product.id) ~ '-' ~ (regularPriceValue|number_format(context.currency.itemRounding.decimals, '.', '')) %}
    {% set feedRow = feedRow|merge({
        'offer_id': offerId,
        'group_id': product.parentId ? product.parentId : product.id,
        'item_group_title': title
    }) %}

    {% if isConcreteVariant %}
        {# Collect resolved variant output fields and the final OpenAI variant_dict payload #}
        {% set mappedVariantOptions = {} %}
        {% set variantDict = {} %}
        {% set customVariantEntries = [] %}

        {# Property groups used by specific mappings (color/size/...) are excluded from custom variants #}
        {% set reservedCustomGroupIds = [] %}
        {% if provider.variantMapping is defined and provider.variantMapping %}
            {% for mappingProperty, propertyGroupIds in provider.variantMapping %}
                {% if mappingProperty != 'custom_variants' and propertyGroupIds %}
                    {% for propertyGroupId in propertyGroupIds %}
                        {% if propertyGroupId and propertyGroupId not in reservedCustomGroupIds %}
                            {% set reservedCustomGroupIds = reservedCustomGroupIds|merge([propertyGroupId]) %}
                        {% endif %}
                    {% endfor %}
                {% endif %}
            {% endfor %}
        {% endif %}
        {% if provider.variantMapping is defined and provider.variantMapping %}
            {% for mappingProperty, propertyGroupIds in provider.variantMapping %}
                {% if propertyGroupIds %}
                    {% if mappingProperty == 'custom_variants' %}
                        {# Resolve up to 3 custom variant entries: category (group name) + option (selected value) #}
                        {% for customPropertyGroupId in propertyGroupIds %}
                            {% if customVariantEntries|length < 3 and customPropertyGroupId not in reservedCustomGroupIds %}
                                {% set customOptionName = null %}
                                {% set customCategoryName = null %}

                                {# Try to resolve custom category and option from direct product options first #}
                                {% if product.options is defined and product.options %}
                                    {% for option in product.options %}
                                        {% if customOptionName is null and option.groupId and option.groupId == customPropertyGroupId %}
                                            {% set optionName = option.translated.name|default(option.name)|default('') %}
                                            {% if optionName %}
                                                {% set customOptionName = optionName %}
                                            {% endif %}

                                            {% if option.group is defined and option.group %}
                                                {% set groupName = option.group.translated.name|default(option.group.name)|default('') %}
                                                {% if groupName %}
                                                    {% set customCategoryName = groupName %}
                                                {% endif %}
                                            {% endif %}
                                        {% endif %}
                                    {% endfor %}
                                {% endif %}

                                {# Fallback to sortedProperties when category or option is still missing #}
                                {% if (customOptionName is null or customCategoryName is null) and product.sortedProperties is defined and product.sortedProperties %}
                                    {% for group in product.sortedProperties %}
                                        {% if group.id and group.id == customPropertyGroupId %}
                                            {% if customCategoryName is null %}
                                                {% set groupName = group.translated.name|default(group.name)|default('') %}
                                                {% if groupName %}
                                                    {% set customCategoryName = groupName %}
                                                {% endif %}
                                            {% endif %}

                                            {# Pick the first option value from the matched custom property group #}
                                            {% if customOptionName is null and group.options is defined and group.options %}
                                                {% for option in group.options %}
                                                    {% if customOptionName is null %}
                                                        {% set optionName = option.translated.name|default(option.name)|default('') %}
                                                        {% if optionName %}
                                                            {% set customOptionName = optionName %}
                                                        {% endif %}
                                                    {% endif %}
                                                {% endfor %}
                                            {% endif %}
                                        {% endif %}
                                    {% endfor %}
                                {% endif %}

                                {# Add custom variant entry when both category and option were resolved #}
                                {% if customCategoryName and customOptionName %}
                                    {% set customVariantEntries = customVariantEntries|merge([{
                                        'category': customCategoryName,
                                        'option': customOptionName
                                    }]) %}
                                {% endif %}
                            {% endif %}
                        {% endfor %}
                    {% else %}
                        {# Resolve standard mappings (color/size/size_system/gender/material): first matching option wins #}
                        {% set variantPropertyOptionName = null %}

                        {# Check direct variant options on the product first #}
                        {% if product.options is defined and product.options %}
                            {% for option in product.options %}
                                {% if variantPropertyOptionName is null and option.groupId and option.groupId in propertyGroupIds %}
                                    {% set optionName = option.translated.name|default(option.name)|default('') %}
                                    {% if optionName %}
                                        {% set variantPropertyOptionName = optionName %}
                                    {% endif %}
                                {% endif %}
                            {% endfor %}
                        {% endif %}

                        {# Fallback to sortedProperties when no direct option matches #}
                        {% if variantPropertyOptionName is null and product.sortedProperties is defined and product.sortedProperties %}
                            {% for group in product.sortedProperties %}
                                {% if variantPropertyOptionName is null and group.id and group.id in propertyGroupIds and group.options is defined and group.options %}
                                    {% for option in group.options %}
                                        {% if variantPropertyOptionName is null %}
                                            {% set optionName = option.translated.name|default(option.name)|default('') %}
                                            {% if optionName %}
                                                {% set variantPropertyOptionName = optionName %}
                                            {% endif %}
                                        {% endif %}
                                    {% endfor %}
                                {% endif %}
                            {% endfor %}
                        {% endif %}

                        {# Add resolved standard variant value for the mapped OpenAI field #}
                        {% if variantPropertyOptionName is not null %}
                            {% set mappedVariantOptions = mappedVariantOptions|merge({
                                (mappingProperty): variantPropertyOptionName
                            }) %}
                        {% endif %}
                    {% endif %}
                {% endif %}
            {% endfor %}
        {% endif %}

        {% if mappedVariantOptions is not empty %}
            {# Standard mapped fields are emitted as top-level fields and mirrored into variant_dict #}
            {% for variantKey, variantValue in mappedVariantOptions %}
                {% if variantValue %}
                    {% set variantDict = variantDict|merge({
                        (variantKey): variantValue
                    }) %}
                {% endif %}
            {% endfor %}
            {% set feedRow = feedRow|merge(mappedVariantOptions) %}
        {% endif %}

        {% if customVariantEntries is not empty %}
            {# Emit custom variants as custom_variant1..3_(category|option) and mirror into variant_dict #}
            {% for customEntry in customVariantEntries %}
                {% set customIndex = loop.index %}
                {% if customIndex <= 3 %}
                    {% set feedRow = feedRow|merge({
                        ('custom_variant' ~ customIndex ~ '_category'): customEntry.category,
                        ('custom_variant' ~ customIndex ~ '_option'): customEntry.option
                    }) %}
                    {% set variantDict = variantDict|merge({
                        (customEntry.category): customEntry.option
                    }) %}
                {% endif %}
            {% endfor %}
        {% endif %}

        {% if variantDict is not empty %}
            {# Attaching OpenAI variants object for this exported item #}
            {% set feedRow = feedRow|merge({
                'variant_dict': variantDict
            }) %}
        {% endif %}
    {% endif %}
{% endif %}

{# Product measurements: OpenAI ACP uses separate value + unit fields (no product_detail equivalent). #}
{% set measurements = agentic_product_measurements(product) %}
{% if measurements.weight %}
    {% set feedRow = feedRow|merge({
        'weight': measurements.weight.value,
        'item_weight_unit': measurements.weight.unit
    }) %}
{% endif %}
{% set dimensionsUnit = null %}
{% if measurements.length %}
    {% set feedRow = feedRow|merge({'length': measurements.length.value}) %}
    {% set dimensionsUnit = measurements.length.unit %}
{% endif %}
{% if measurements.width %}
    {% set feedRow = feedRow|merge({'width': measurements.width.value}) %}
    {% set dimensionsUnit = measurements.width.unit %}
{% endif %}
{% if measurements.height %}
    {% set feedRow = feedRow|merge({'height': measurements.height.value}) %}
    {% set dimensionsUnit = measurements.height.unit %}
{% endif %}
{% if dimensionsUnit %}
    {% set feedRow = feedRow|merge({'dimensions_unit': dimensionsUnit}) %}
{% endif %}

{# Skip rows that are missing core required feed data and would be invalid for OpenAI. #}
{% if title and imageUrl and price %}
    {{ feedRow|json_encode(constant('JSON_UNESCAPED_SLASHES'))|raw }}
{% endif %}
`;Shopware.Service("exportTemplateService").registerProductExportTemplate({name:"open_ai",translationKey:"sw-sales-channel.detail.agenticCommerce.templates.template-label.open-ai",salesChannelTypeId:Shopware.Defaults.agenticCommerceTypeId,providerName:"open-ai",headerTemplate:"",bodyTemplate:P.trim(),footerTemplate:"",encoding:"UTF-8",fileFormat:"jsonl",generateByCronjob:!1,interval:86400});var N=`<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <atom:link href="{{ productExport.salesChannelDomain.url|escape }}/store-api/product-export/{{ productExport.accessKey|escape }}/{{ productExport.fileName|escape }}" rel="self" type="application/rss+xml" />
        <title>{{ context.salesChannel.name|escape }}</title>
        <description>{{ context.salesChannel.name|escape }}</description>
        <link>{{ productExport.salesChannelDomain.url|escape }}</link>
        <language>{{ productExport.salesChannelDomain.language.locale.code|escape }}</language>`;var D=`{%- set title = product.translated.name|default(product.name)|default('')|trim -%}
{%- set description = product.translated.description|default(title)|default('')|striptags|trim -%}
{%- set price = product.calculatedPrice -%}
{%- if product.calculatedPrices.count > 0 -%}
    {%- set price = product.calculatedPrices.last -%}
{%- endif -%}
{%- set unitPrice = price.unitPrice -%}
{%- set regularPriceValue = unitPrice -%}
{%- set salePriceValue = null -%}
{%- if price.listPrice is defined and price.listPrice -%}
    {%- set regularPriceValue = price.listPrice.price -%}
    {%- if price.listPrice.price > unitPrice -%}
        {%- set salePriceValue = unitPrice -%}
    {%- endif -%}
{%- endif -%}
{%- set imageUrl = '' -%}
{%- if product.cover is defined and product.cover and product.cover.media is defined and product.cover.media -%}
    {%- set imageUrl = product.cover.media.url -%}
{%- endif -%}
{%- set additionalImageUrls = [] -%}
{%- if product.media is defined and product.media -%}
    {%- for productMedia in product.media -%}
        {%- if productMedia.media is defined and productMedia.media and productMedia.media.url and productMedia.id != product.coverId -%}
            {%- set additionalImageUrls = additionalImageUrls|merge([productMedia.media.url]) -%}
        {%- endif -%}
    {%- endfor -%}
{%- endif -%}
{%- set hasVariantListing = productExport.includeVariants and (product.parentId or product.childCount > 0) -%}
{%- set canonicalUrl = seoUrl('frontend.detail.page', {'productId': product.id}) -%}
{%- set productUrl = canonicalUrl ~ '?referringSalesChannel=' ~ provider.referringSalesChannel -%}
{%- if provider.affiliateCode -%}
    {%- set productUrl = productUrl ~ '&affiliateCode=' ~ provider.affiliateCode|url_encode -%}
{%- endif -%}
{%- if provider.campaignCode -%}
    {%- set productUrl = productUrl ~ '&campaignCode=' ~ provider.campaignCode|url_encode -%}
{%- endif -%}

{%- set itemId = product.productNumber ? product.productNumber : product.id -%}
{%- set availability = product.available ? 'in_stock' : (product.restockTime ? 'backorder' : 'out_of_stock') -%}
{%- set brand = (product.manufacturer is defined and product.manufacturer) ? product.manufacturer.translated.name : provider.sellerName -%}
{%- set gtin = product.ean|default('') -%}
{%- set mpn = product.manufacturerNumber|default('') -%}
{%- set categoryPath = '' -%}
{%- if product.categories is defined and product.categories and product.categories.count > 0 -%}
    {%- set categoryPath = product.categories.first.getBreadCrumb|slice(1)|join(' > ') -%}
{%- endif -%}

{%- set mappedVariantOptions = {} -%}
{%- set customVariantEntries = [] -%}
{%- set reservedCustomGroupIds = [] -%}
{%- if provider.variantMapping is defined and provider.variantMapping -%}
    {%- for mappingProperty, propertyGroupIds in provider.variantMapping -%}
        {%- if mappingProperty != 'custom_variants' and propertyGroupIds -%}
            {%- for propertyGroupId in propertyGroupIds -%}
                {%- if propertyGroupId and propertyGroupId not in reservedCustomGroupIds -%}
                    {%- set reservedCustomGroupIds = reservedCustomGroupIds|merge([propertyGroupId]) -%}
                {%- endif -%}
            {%- endfor -%}
        {%- endif -%}
    {%- endfor -%}
{%- endif -%}
{%- if provider.variantMapping is defined and provider.variantMapping -%}
    {%- for mappingProperty, propertyGroupIds in provider.variantMapping -%}
        {%- if propertyGroupIds -%}
            {%- if mappingProperty == 'custom_variants' -%}
                {%- for customPropertyGroupId in propertyGroupIds -%}
                    {%- if customVariantEntries|length < 3 and customPropertyGroupId not in reservedCustomGroupIds -%}
                        {%- set customOptionName = null -%}
                        {%- set customCategoryName = null -%}

                        {%- if product.options is defined and product.options -%}
                            {%- for option in product.options -%}
                                {%- if customOptionName is null and option.groupId and option.groupId == customPropertyGroupId -%}
                                    {%- set optionName = option.translated.name|default(option.name)|default('') -%}
                                    {%- if optionName -%}
                                        {%- set customOptionName = optionName -%}
                                    {%- endif -%}

                                    {%- if option.group is defined and option.group -%}
                                        {%- set groupName = option.group.translated.name|default(option.group.name)|default('') -%}
                                        {%- if groupName -%}
                                            {%- set customCategoryName = groupName -%}
                                        {%- endif -%}
                                    {%- endif -%}
                                {%- endif -%}
                            {%- endfor -%}
                        {%- endif -%}

                        {%- if (customOptionName is null or customCategoryName is null) and product.sortedProperties is defined and product.sortedProperties -%}
                            {%- for group in product.sortedProperties -%}
                                {%- if group.id and group.id == customPropertyGroupId -%}
                                    {%- if customCategoryName is null -%}
                                        {%- set groupName = group.translated.name|default(group.name)|default('') -%}
                                        {%- if groupName -%}
                                            {%- set customCategoryName = groupName -%}
                                        {%- endif -%}
                                    {%- endif -%}

                                    {%- if customOptionName is null and group.options is defined and group.options -%}
                                        {%- for option in group.options -%}
                                            {%- if customOptionName is null -%}
                                                {%- set optionName = option.translated.name|default(option.name)|default('') -%}
                                                {%- if optionName -%}
                                                    {%- set customOptionName = optionName -%}
                                                {%- endif -%}
                                            {%- endif -%}
                                        {%- endfor -%}
                                    {%- endif -%}
                                {%- endif -%}
                            {%- endfor -%}
                        {%- endif -%}

                        {%- if customCategoryName and customOptionName -%}
                            {%- set customVariantEntries = customVariantEntries|merge([{
                                'category': customCategoryName,
                                'option': customOptionName
                            }]) -%}
                        {%- endif -%}
                    {%- endif -%}
                {%- endfor -%}
            {%- else -%}
                {%- set variantPropertyOptionName = null -%}

                {%- if product.options is defined and product.options -%}
                    {%- for option in product.options -%}
                        {%- if variantPropertyOptionName is null and option.groupId and option.groupId in propertyGroupIds -%}
                            {%- set optionName = option.translated.name|default(option.name)|default('') -%}
                            {%- if optionName -%}
                                {%- set variantPropertyOptionName = optionName -%}
                            {%- endif -%}
                        {%- endif -%}
                    {%- endfor -%}
                {%- endif -%}

                {%- if variantPropertyOptionName is null and product.sortedProperties is defined and product.sortedProperties -%}
                    {%- for group in product.sortedProperties -%}
                        {%- if variantPropertyOptionName is null and group.id and group.id in propertyGroupIds and group.options is defined and group.options -%}
                            {%- for option in group.options -%}
                                {%- if variantPropertyOptionName is null -%}
                                    {%- set optionName = option.translated.name|default(option.name)|default('') -%}
                                    {%- if optionName -%}
                                        {%- set variantPropertyOptionName = optionName -%}
                                    {%- endif -%}
                                {%- endif -%}
                            {%- endfor -%}
                        {%- endif -%}
                    {%- endfor -%}
                {%- endif -%}

                {%- if variantPropertyOptionName is not null -%}
                    {%- set mappedVariantOptions = mappedVariantOptions|merge({
                        (mappingProperty): variantPropertyOptionName
                    }) -%}
                {%- endif -%}
            {%- endif -%}
        {%- endif -%}
    {%- endfor -%}
{%- endif -%}

{# Skip rows that are missing core required Google fields. #}
{%- if title and imageUrl and price -%}
<item>
    <g:id>{{ itemId|escape }}</g:id>
    <title>{{ title|escape }}</title>
    <description>{{ description|escape }}</description>
    <link>{{ productUrl|escape }}</link>
    <g:canonical_link>{{ canonicalUrl|escape }}</g:canonical_link>
    <g:image_link>{{ imageUrl|escape }}</g:image_link>
    {%- for additionalImageUrl in additionalImageUrls|slice(0, 10) %}
    <g:additional_image_link>{{ additionalImageUrl|escape }}</g:additional_image_link>
    {%- endfor %}
    <g:availability>{{ availability }}</g:availability>
    <g:price>{{ regularPriceValue|number_format(context.currency.itemRounding.decimals, '.', '') }} {{ context.currency.isoCode }}</g:price>
    {%- if salePriceValue is not null %}
    <g:sale_price>{{ salePriceValue|number_format(context.currency.itemRounding.decimals, '.', '') }} {{ context.currency.isoCode }}</g:sale_price>
    {%- endif %}
    {%- if mappedVariantOptions.condition is defined and mappedVariantOptions.condition %}
    <g:condition>{{ mappedVariantOptions.condition|escape }}</g:condition>
    {%- else %}
    <g:condition>new</g:condition>
    {%- endif %}
    <g:brand>{{ brand|escape }}</g:brand>
    {%- if gtin %}
    <g:gtin>{{ gtin|escape }}</g:gtin>
    {%- endif %}
    {%- if mpn %}
    <g:mpn>{{ mpn|escape }}</g:mpn>
    {%- endif %}
    {%- if not gtin and not mpn %}
    <g:identifier_exists>no</g:identifier_exists>
    {%- endif %}
    {%- if categoryPath %}
    <g:product_type>{{ categoryPath|escape }}</g:product_type>
    {%- endif %}
    {%- if hasVariantListing %}
    <g:item_group_id>{{ (product.parentId ? product.parentId : product.id)|escape }}</g:item_group_id>
    {%- endif %}
    {%- if mappedVariantOptions.color is defined and mappedVariantOptions.color %}
    <g:color>{{ mappedVariantOptions.color|escape }}</g:color>
    {%- endif %}
    {%- if mappedVariantOptions.size is defined and mappedVariantOptions.size %}
    <g:size>{{ mappedVariantOptions.size|escape }}</g:size>
    {%- endif %}
    {%- if mappedVariantOptions.size_system is defined and mappedVariantOptions.size_system %}
    <g:size_system>{{ mappedVariantOptions.size_system|escape }}</g:size_system>
    {%- endif %}
    {%- if mappedVariantOptions.gender is defined and mappedVariantOptions.gender %}
    <g:gender>{{ mappedVariantOptions.gender|escape }}</g:gender>
    {%- endif %}
    {%- if mappedVariantOptions.age_group is defined and mappedVariantOptions.age_group %}
    <g:age_group>{{ mappedVariantOptions.age_group|escape }}</g:age_group>
    {%- endif %}
    {%- if mappedVariantOptions.material is defined and mappedVariantOptions.material %}
    <g:material>{{ mappedVariantOptions.material|escape }}</g:material>
    {%- endif %}
    {%- for customEntry in customVariantEntries %}
        {%- set customIndex = loop.index0 %}
        {%- if customIndex < 3 %}
    <g:custom_label_{{ customIndex }}>{{ (customEntry.category ~ ': ' ~ customEntry.option)|escape }}</g:custom_label_{{ customIndex }}>
        {%- endif %}
    {%- endfor %}
    {%- if product.shippingFree %}
    <g:shipping>
        <g:country>{{ (provider.shippingCountry ?: provider.storeCountry)|escape }}</g:country>
        {%- if provider.shippingService %}
        <g:service>{{ provider.shippingService|escape }}</g:service>
        {%- endif %}
        <g:price>0.00 {{ context.currency.isoCode }}</g:price>
    </g:shipping>
    {%- endif %}
    {%- for characteristic in agentic_essential_characteristics(product, context) %}
    <g:product_detail>
        <g:section_name>{{ characteristic.section|escape }}</g:section_name>
        <g:attribute_name>{{ characteristic.name|escape }}</g:attribute_name>
        <g:attribute_value>{{ characteristic.value|escape }}</g:attribute_value>
    </g:product_detail>
    {%- endfor %}
    {%- set measurements = agentic_product_measurements(product) %}
    {%- if measurements.weight %}
    <g:product_weight>{{ measurements.weight.display|escape }}</g:product_weight>
    {%- endif %}
    {%- if measurements.length %}
    <g:product_length>{{ measurements.length.display|escape }}</g:product_length>
    {%- endif %}
    {%- if measurements.width %}
    <g:product_width>{{ measurements.width.display|escape }}</g:product_width>
    {%- endif %}
    {%- if measurements.height %}
    <g:product_height>{{ measurements.height.display|escape }}</g:product_height>
    {%- endif %}
    {%- if measurements.unitPricingMeasure %}
    <g:unit_pricing_measure>{{ measurements.unitPricingMeasure|escape }}</g:unit_pricing_measure>
    {%- endif %}
    {%- if measurements.unitPricingBaseMeasure %}
    <g:unit_pricing_base_measure>{{ measurements.unitPricingBaseMeasure|escape }}</g:unit_pricing_base_measure>
    {%- endif %}
</item>
{%- endif -%}
`;var R=`    </channel>
</rss>`;Shopware.Service("exportTemplateService").registerProductExportTemplate({name:"google",translationKey:"sw-sales-channel.detail.agenticCommerce.templates.template-label.google",salesChannelTypeId:Shopware.Defaults.agenticCommerceTypeId,providerName:"google",headerTemplate:N.trim(),bodyTemplate:D,footerTemplate:R.trim(),encoding:"UTF-8",fileFormat:"xml",generateByCronjob:!1,interval:86400});var $=`<!-- eslint-disable vue/valid-v-slot -->
{% block sw_sales_channel_list_grid_column_name %}
<template #column-name="{ item }">
    <sw-icon
        v-if="item.type && item.type.iconName === 'regular-sparkle'"
        name="regular-sparkle"
        size="18px"
    />
    <sw-icon
        v-else
        :name="item.type.iconName"
        size="18px"
    />
    <router-link
        :to="{
            name: 'sw.sales.channel.detail',
            params: { id: item.id }
        }"
    >{{ item.translated.name || item.name }}</router-link>
</template>
{% endblock %}

{% block sw_sales_channel_list_grid_column_status %}
{% parent %}
<template #column-ucpActive="{ item }">
    <sw-status v-if="ucpActiveMap[item.id]" color="green">
        {{ $t('swagAgenticCommerce.salesChannelList.ucpExposed') }}
    </sw-status>
    <sw-status v-else color="gray">
        {{ $t('swagAgenticCommerce.salesChannelList.ucpOff') }}
    </sw-status>
</template>
{% endblock %}
`;var{Component:fe}=Shopware,_e={template:$,inject:["acl"],data(){return{ucpActiveMap:{}}},computed:{canViewUcpStatus(){return this.acl?.can?.("ucp.viewer")===!0},salesChannelColumns(){let e=this.$super("salesChannelColumns");if(!this.canViewUcpStatus||e.some(i=>i.property==="ucpActive"))return e;let t={property:"ucpActive",label:"swagAgenticCommerce.salesChannelList.columnUcp",allowResize:!1,sortable:!1,align:"center"},a=e.findIndex(i=>i.property==="status");return a>=0?e.splice(a+1,0,t):e.push(t),e}},created(){this.canViewUcpStatus&&this.loadUcpActiveStates()},methods:{loadUcpActiveStates(){if(!this.canViewUcpStatus)return;let e=Shopware.Service("ucpAdminApiService");e?.getSalesChannels&&e.getSalesChannels().then(t=>{let a={};(t?.data?.data??[]).forEach(i=>{a[i.id]=!!i.ucp?.active}),this.ucpActiveMap=a}).catch(()=>{})}}};fe.override("sw-sales-channel-list",_e);var U=`{% block sw_agentic_commerce_tracking_config %}
<sw-card
    :title="$t('sw-sales-channel.agenticCommerceTracking.cardTitle')"
    class="sw-agentic-commerce-tracking-config"
    position-identifier="sw-agentic-commerce-tracking-config"
>
    {% block sw_agentic_commerce_tracking_config_description %}
    <p class="sw-agentic-commerce-tracking-config__description">
        {{ $t('sw-sales-channel.agenticCommerceTracking.description') }}
    </p>
    {% endblock %}

    {% block sw_agentic_commerce_tracking_config_affiliate %}
    <sw-text-field
        :value="trackingConfig.affiliateCode"
        :label="$t('sw-sales-channel.agenticCommerceTracking.affiliateCodeLabel')"
        :placeholder="$t('sw-sales-channel.agenticCommerceTracking.affiliateCodePlaceholder')"
        :disabled="disabled || undefined"
        @input="onAffiliateCodeChange"
        @update:value="onAffiliateCodeChange"
    />
    {% endblock %}

    {% block sw_agentic_commerce_tracking_config_campaign %}
    <sw-text-field
        :value="trackingConfig.campaignCode"
        :label="$t('sw-sales-channel.agenticCommerceTracking.campaignCodeLabel')"
        :placeholder="$t('sw-sales-channel.agenticCommerceTracking.campaignCodePlaceholder')"
        :disabled="disabled || undefined"
        @input="onCampaignCodeChange"
        @update:value="onCampaignCodeChange"
    />
    {% endblock %}
</sw-card>
{% endblock %}
`;function d(e,t){let{Component:a}=Shopware;if(a.getComponentRegistry().has(e)){a.override(e,t);return}a.register(e,t)}d("sw-agentic-commerce-tracking-config",{template:U,emits:["change"],props:{salesChannel:{type:Object,required:!0},disabled:{type:Boolean,required:!1,default:!1}},computed:{trackingConfig(){return this.salesChannel.configuration||(this.salesChannel.configuration={}),this.salesChannel.configuration}},methods:{onAffiliateCodeChange(e){e instanceof Event||(this.trackingConfig.affiliateCode=e??"",this.$emit("change",{...this.trackingConfig}))},onCampaignCodeChange(e){e instanceof Event||(this.trackingConfig.campaignCode=e??"",this.$emit("change",{...this.trackingConfig}))}}});var V=`{% block sw_sales_channel_grid_columns_icon %}
<sw-grid-column
    flex="85px"
    data-index="icon"
    class="sw-sales-channel-modal-grid__icon-column"
    label="icon"
>
    <!-- eslint-disable-next-line vuejs-accessibility/click-events-have-key-events -->
    <span
        class="sw-sales-channel-modal-grid__icon"
        @click="onOpenDetail(item.id)"
    >
        <sw-icon
            v-if="isAgenticCommerceSalesChannelType(item.id)"
            name="regular-sparkle"
            size="20px"
        />
        <sw-icon
            v-else-if="item.iconName"
            :name="item.iconName"
        />
    </span>
</sw-grid-column>
{% endblock %}

{% block sw_sales_channel_grid_columns_content %}
<sw-grid-column
    flex="minmax(150px, 1fr)"
    data-index="content"
    label="content"
>
    <div class="sw-sales-channel-modal-grid__item-content">
        <h3
            class="sw-sales-channel-modal-grid__item-name"
            role="button"
            tabindex="0"
            @click="onOpenDetail(item.id)"
            @keydown.enter="onOpenDetail(item.id)"
        >
            <span>{{ item.translated.name }}</span>
            <sw-label
                v-if="isAgenticCommerceSalesChannelType(item.id)"
                appearance="pill"
                variant="success"
                size="small"
                class="sw-sales-channel-modal-grid__agentic-badge"
            >
                {{ $t('swagAgenticCommerce.modalGrid.agenticCommerceBadge') }}
            </sw-label>
        </h3>
        <div
            class="sw-sales-channel-modal-grid__item-description"
            role="button"
            tabindex="0"
            @click="onOpenDetail(item.id)"
            @keydown.enter="onOpenDetail(item.id)"
        >{{ item.translated.description }}</div>
    </div>
</sw-grid-column>
{% endblock %}

{% block sw_sales_channel_grid_columns_actions %}
<sw-grid-column
    flex="auto"
    align="center"
    data-index="actions"
    class="sw-sales-channel-modal-grid__actions"
    label="actions"
>
    <sw-button
        v-tooltip="{
            message: $t('sw-sales-channel.modal.messageNoProductStreams'),
            showOnDisabledElements: true,
            disabled: !addChannelAction.disabled(item.id)
        }"
        class="sw-sales-channel-modal__add-channel-action"
        size="small"
        variant="primary"
        :is-loading="addChannelAction.loading(item.id)"
        :disabled="addChannelAction.disabled(item.id)"
        data-analytics-id="sw_sales_channel.modal_grid.sales_channel_add"
        :data-analytics-sales-channel-type-id="item.id"
        @click="onAddChannel(item.id)"
    >
        {{ $t('sw-sales-channel.modal.buttonAddChannel') }}
    </sw-button>
</sw-grid-column>
{% endblock %}
`;var{Component:ve,Defaults:be}=Shopware;ve.override("sw-sales-channel-modal-grid",{template:V,methods:{isAgenticCommerceSalesChannelType(e){return e===be.agenticCommerceTypeId}}});var{Component:ye,Defaults:L}=Shopware;ye.override("sw-sales-channel-modal",{methods:{isProductComparisonSalesChannelType(e){return e===L.productComparisonTypeId||e===L.agenticCommerceTypeId}}});var M=`{% block sw_sales_channel_detail_actions_save %}
<sw-button-process
    v-tooltip.bottom="tooltipSave"
    class="sw-sales-channel-detail__save-action"
    :is-loading="isLoading"
    :process-success="isSaveSuccessful"
    :disabled="!allowSaving || isLoading || productComparison.invalidFileName"
    variant="primary"
    data-analytics-id="sw_sales_channel.detail.sales_channel_save"
    :data-analytics-sales-channel-type-id="salesChannel?.typeId ?? ''"
    :data-analytics-sales-channel-is-new="salesChannel?.isNew?.() ?? false"
    @process-finish="saveFinish"
    @update:process-success="saveFinish"
    @click.prevent="onSave"
>
    {{ $t('global.default.save') }}
</sw-button-process>
{% endblock %}

{% block sw_sales_channel_detail_content_tab_theme %}
<sw-tabs-item
    v-if="!isAgenticCommerce && !isProductComparison"
    :disabled="isLoading"
    :route="{ name: 'sw.sales.channel.detail.theme', params: { id: $route.params.id } }"
    :title="$t('sw-sales-channel.detail.tabTheme')"
>
    {{ $t('sw-sales-channel.detail.tabTheme') }}
</sw-tabs-item>
{% endblock %}

{% block sw_sales_channel_detail_content_tab_agentic_commerce_integration %}{% endblock %}

{% block sw_sales_channel_detail_content_tab_product_comparison %}
<sw-tabs-item
    v-if="shouldRenderAgenticUi && !isLoading && Boolean(salesChannel?.id)"
    :route="{ name: 'sw.sales.channel.detail.agenticCommerceStatistics', params: { id: $route.params.id } }"
    :title="$t('sw-sales-channel.detail.productExport.tabInsights')"
>
    {{ $t('sw-sales-channel.detail.productExport.tabInsights') }}
</sw-tabs-item>

<sw-tabs-item
    v-if="shouldRenderAgenticCommerceTab && !isLoading"
    :route="{ name: 'sw.sales.channel.detail.agenticCommerce', params: { id: $route.params.id } }"
    :title="$t('sw-sales-channel.detail.agenticCommerce.tabAgenticCommerce')"
>
    {{ $t('sw-sales-channel.detail.agenticCommerce.tabAgenticCommerce') }}
</sw-tabs-item>

{% parent %}
{% endblock %}

{% block sw_sales_channel_detail_content_view %}
<template v-if="isLoading">
    <sw-skeleton />
    <sw-skeleton />
</template>

<template v-else-if="useRouterViewSlot">
    <router-view :key="$route.params.id" v-slot="{ Component }">
        <component
            :is="Component"
            :sales-channel="salesChannel"
            :product-export="productExport"
            :agentic-commerce-export-config="agenticCommerceExportConfig"
            :storefront-sales-channel-criteria="storefrontSalesChannelCriteria"
            :custom-field-sets="customFieldSets"
            :is-loading="isLoading"
            :product-comparison-access-url="productComparison.productComparisonAccessUrl"
            :template-options="productComparison.templateOptions"
            :show-template-modal="productComparison.showTemplateModal"
            :template-name="productComparison.templateName"
            @template-selected="onTemplateSelected"
            @access-key-changed="generateAccessUrl"
            @domain-changed="generateAccessUrl"
            @invalid-file-name="setInvalidFileName(true)"
            @valid-file-name="setInvalidFileName(false)"
            @template-modal-close="onTemplateModalClose"
            @template-modal-confirm="onTemplateModalConfirm"
        />
    </router-view>
</template>

<router-view
    v-else
    :key="$route.params.id"
    :sales-channel="salesChannel"
    :product-export="productExport"
    :agentic-commerce-export-config="agenticCommerceExportConfig"
    :storefront-sales-channel-criteria="storefrontSalesChannelCriteria"
    :custom-field-sets="customFieldSets"
    :is-loading="isLoading"
    :product-comparison-access-url="productComparison.productComparisonAccessUrl"
    :template-options="productComparison.templateOptions"
    :show-template-modal="productComparison.showTemplateModal"
    :template-name="productComparison.templateName"
    @template-selected="onTemplateSelected"
    @access-key-changed="generateAccessUrl"
    @domain-changed="generateAccessUrl"
    @invalid-file-name="setInvalidFileName(true)"
    @valid-file-name="setInvalidFileName(false)"
    @template-modal-close="onTemplateModalClose"
    @template-modal-confirm="onTemplateModalConfirm"
/>
{% endblock %}
`;var F="2026-04-08";function h(){return{active:!1,ucpVersion:F,profileDomain:null,enabledCapabilities:["catalog","cart","discount","checkout","order"],enabledTransports:["rest"]}}var z=["enabledCapabilities","enabledTransports"],Se=["profileDomain"];function f(e={}){let t=h(),a={...t};return Object.keys(t).forEach(i=>{e[i]!==void 0&&(a[i]=e[i])}),z.forEach(i=>{a[i]=Array.isArray(e[i])?e[i]:t[i]}),Se.forEach(i=>{a[i]=e[i]||null}),a}function p(e){let t={...e};return z.forEach(a=>{t[a]=Array.isArray(e[a])?[...e[a]]:[]}),t}function y(e,t,a){let i=[...e],n=i.indexOf(t);return a&&n===-1&&i.push(t),!a&&n!==-1&&i.splice(n,1),i}function x(e){let t=e?.response?.data?.errors;if(Array.isArray(t)&&t.length>0){let i=t.map(n=>n?.detail||n?.title).filter(n=>typeof n=="string"&&n.length>0);if(i.length>0)return i.join(`
`)}let a=e?.response?.statusText;return typeof a=="string"&&a.length>0?a:e?.message||"Unknown UCP administration error."}var{Defaults:G}=Shopware;function _(e){return[G.storefrontSalesChannelTypeId,G.apiSalesChannelTypeId].includes(e)}var{Component:ke,Context:Ae,Defaults:C}=Shopware,Te=Shopware.Utils.object,Ee=Shopware.Classes.ShopwareError,Ie={template:M,inject:["systemConfigApiService","ucpAdminApiService","acl"],provide(){return{swSalesChannelDetailGetAgenticCommerceExportConfig:()=>this.agenticCommerceExportConfig,swSalesChannelGetUcpState:()=>this.ucpState}},data(){return{agenticCommerceExportConfig:[],previousTemplateName:null,ucpState:{loaded:!1,isLoading:!1,form:h(),savedForm:h(),meta:{},preview:null}}},watch:{"productComparison.templateOptions"(e){e?.length&&this.detectCurrentTemplate()},"productExport.provider"(){this.detectCurrentTemplate(),this.syncExportFileName()}},computed:{useRouterViewSlot(){return typeof this.$router?.hasRoute=="function"},isAgenticCommerce(){return this.salesChannel?this.salesChannel.typeId===C.agenticCommerceTypeId:this.$route.params.typeId===C.agenticCommerceTypeId},shouldRenderAgenticUi(){return this.isAgenticCommerce&&!c},shouldRenderAgenticCommerceTab(){let e=this.salesChannel?.typeId??this.$route.params.typeId;return this.acl.can("ucp.viewer")&&(_(e)||this.isAgenticCommerce)},isProductComparison(){return this.isAgenticCommerce?!0:this.salesChannel?this.salesChannel.typeId===C.productComparisonTypeId:this.$route.params.typeId===C.productComparisonTypeId},defaultAgenticCommerceExportConfig(){return[{provider:"open-ai",systemConfigDomain:"SwagAgenticCommerce.openAiProductExport",titleSnippet:"sw-sales-channel.detail.agenticCommerce.openAiSettingsTitle",positionIdentifier:"sw-sales-channel-detail-base-agentic-commerce-export-config-provider"},{provider:"google",systemConfigDomain:"SwagAgenticCommerce.googleProductExport",titleSnippet:"sw-sales-channel.detail.agenticCommerce.googleSettingsTitle",positionIdentifier:"sw-sales-channel-detail-base-agentic-commerce-export-config-provider"}]}},methods:{createdComponent(){this.$super("createdComponent"),this.isAgenticCommerce&&this.productExport?.isNew()&&this.onTemplateSelected("open_ai")},loadEntityData(){let e=!!this.$route.params.id,t=!!this.$route.params.typeId;if(!e&&t&&this.salesChannel?.id){this.loadAgenticCommerceExportConfig();return}if(e){if(t){this.loadAgenticCommerceExportConfig();return}this.salesChannel&&(this.salesChannel=null),this.loadSalesChannel(),this.loadCustomFieldSets()}},loadSalesChannel(){this.isLoading=!0,this.salesChannelRepository.get(this.$route.params.id.toLowerCase(),Ae.api,this.getLoadSalesChannelCriteria()).then(e=>{this.salesChannel=e,this.salesChannel.maintenanceIpWhitelist||(this.salesChannel.maintenanceIpWhitelist=[]),this.generateAccessUrl(),this.loadAgenticCommerceExportConfig(),this.loadUcpState(),this.isLoading=!1})},async loadUcpState(){if(!this.shouldRenderAgenticCommerceTab||!this.salesChannel?.id)return;let e=this.salesChannel.id;this.ucpState.isLoading=!0;let t=!1,a=i=>{t||(t=!0,this.ucpState.isLoading=!1,this.createNotificationError({message:x(i)}))};try{await Promise.all([this.ucpAdminApiService.getSalesChannel(e).then(i=>{this.ucpState.meta=i.data.meta||{}},a),this.ucpAdminApiService.getConfig(e).then(i=>{let n=f(i.data.data||{});this.ucpState.form=n,this.ucpState.savedForm=f(n),this.ucpState.loaded=!0},a),this.ucpAdminApiService.getProfilePreview(e).then(i=>{this.ucpState.preview=i.data.data||null},a)])}catch(i){a(i)}finally{this.ucpState.isLoading=!1}},async saveUcpState(e){if(!this.ucpState.loaded||!e)return!0;try{return await this.ucpAdminApiService.saveConfig(e,p(this.ucpState.form)),this.ucpState.savedForm=f(this.ucpState.form),!0}catch(t){return this.createNotificationError({message:x(t)}),!1}},onTemplateSelected(e){if(this.productComparison.templates===null||this.productComparison.templates[e]===void 0)return;if(this.productComparison.selectedTemplate={...this.productComparison.templates[e]},this.productExport.isNew()){this.productComparison.templateName=e,this.onTemplateModalConfirm();return}if(!Object.keys(this.productComparison.selectedTemplate).some(a=>this.productExport[a]!==this.productComparison.selectedTemplate[a])){this.productComparison.templateName=e;return}this.previousTemplateName=this.productComparison.templateName,this.productComparison.showTemplateModal=!0},onTemplateModalClose(){this.productComparison.selectedTemplate=null,this.productComparison.templateName=this.previousTemplateName??null,this.previousTemplateName=null,this.productComparison.showTemplateModal=!1},onTemplateModalConfirm(){let e=this.productComparison.selectedTemplate;Object.keys(e).forEach(t=>{if(t==="providerName"){this.productExport.provider=e[t];return}this.productExport[t]=e[t]}),this.productComparison.templateName=e.name??null,this.productComparison.selectedTemplate=null,this.previousTemplateName=null,this.productComparison.showTemplateModal=!1,!this.productExport.isNew()&&this.createNotificationInfo({message:this.$t("sw-sales-channel.detail.productComparison.templates.message.template-applied-message")})},async onSave(){if(!this.validateAgenticCommerceExportConfig()){this.isLoading=!1;return}let e=typeof this.saveSalesChannel!="function",t=this.salesChannel?.id,a=this.agenticCommerceExportConfig,i=this.productExport?.provider,n;e?(await this.$super("onSave"),n=this.isSaveSuccessful):n=await this.saveSalesChannel(),!(!n||!await this.saveAgenticCommerceExportConfig(t,a,i)||!await this.saveUcpState(t))&&(e||this.loadEntityData())},validateAgenticCommerceExportConfig(){let e=new Ee({code:"c1051bb4-d103-4f74-8988-acbcafc7fdc3"}),t=this.productExport?.provider??this.defaultAgenticCommerceExportConfig[0]?.provider,a=!0,i=this.agenticCommerceExportConfig.filter(n=>n.isLoaded&&n.provider===t);for(let n of i)for(let s of n.elements.filter(r=>r.config?.required&&!n.values[r.name]))n.errors[s.name]=e,a=!1;return a},async loadAgenticCommerceExportConfig(){this.agenticCommerceExportConfig=this.defaultAgenticCommerceExportConfig.map(e=>({...e,elements:[],values:{},errors:{},isLoading:!1,isLoaded:!1})),!(!this.isAgenticCommerce||!this.salesChannel?.id)&&await Promise.all(this.agenticCommerceExportConfig.map(async e=>{e.isLoading=!0;try{let[t,a]=await Promise.all([this.systemConfigApiService.getConfig(e.systemConfigDomain),this.systemConfigApiService.getValues(e.systemConfigDomain,this.salesChannel.id)]);e.elements=t.flatMap(i=>i.elements),e.values=a,e.isLoaded=!0}catch{this.createNotificationError({message:this.$t("sw-sales-channel.detail.messageAPIError")})}finally{e.isLoading=!1}}))},detectCurrentTemplate(){if(this.$route.params.typeId||!this.productComparison.templateOptions?.length||!this.productExport?.provider)return;let e=this.productComparison.templateOptions.find(t=>t.providerName===this.productExport.provider);e&&(this.productComparison.templateName=e.name)},syncExportFileName(){if(!this.productExport?.provider||!this.productExport?.fileName)return;let e=Shopware.Service("exportTemplateService").getProductExportTemplateRegistry(),t=Object.values(e).find(i=>i.providerName===this.productExport.provider);if(!t?.fileFormat)return;let a=this.productExport.fileName.replace(/\.[^.]*$/,"")+"."+t.fileFormat;a!==this.productExport.fileName&&(this.productExport.fileName=a),this.productExport.fileFormat!==t.fileFormat&&(this.productExport.fileFormat=t.fileFormat)},async saveAgenticCommerceExportConfig(e=null,t=null,a=null){let i=e??this.salesChannel?.id,n=t??this.agenticCommerceExportConfig;if(!((e!==null||this.isAgenticCommerce)&&!!i))return!0;let r=a??this.productExport?.provider??this.defaultAgenticCommerceExportConfig[0]?.provider,m=n.filter(u=>u.isLoaded&&u.provider===r);if(m.length===0)return!0;let pe=m.reduce((u,me)=>({...u,...Te.deepCopyObject(me.values)}),{});try{return await this.systemConfigApiService.batchSave({[i]:pe}),!0}catch{return this.createNotificationError({message:this.$t("sw-sales-channel.detail.messageSaveError",{name:this.salesChannel?.name||this.placeholder(this.salesChannel??{},"name")})}),!1}}}};ke.override("sw-sales-channel-detail",Ie,10);var B=`{% block sw_sales_channel_detail_base_general %}
{% parent %}

<template v-if="shouldRenderAgenticUi">
    <sw-card
        v-for="configEntry in resolvedAgenticCommerceExportConfig"
        :key="configEntry.provider"
        :position-identifier="getAgenticCommerceExportCardPositionIdentifier(configEntry)"
        :title="getAgenticCommerceExportCardTitle(configEntry)"
    >
        <sw-skeleton v-if="configEntry.isLoading" />

        <template v-else>
            <sw-form-field-renderer
                v-for="element in configEntry.elements"
                v-bind="getAgenticCommerceExportElementBind(element)"
                :key="\`\${configEntry.provider}-\${element.name}\`"
                :value="configEntry.values[element.name]"
                :error="configEntry.errors && configEntry.errors[element.name] ? configEntry.errors[element.name] : null"
                :disabled="!acl.can('sales_channel.editor') || isLoading || configEntry.isLoading"
                @input="onAgenticCommerceExportFieldUpdate(configEntry, element.name, $event)"
                @update:value="onAgenticCommerceExportFieldUpdate(configEntry, element.name, $event)"
            />
        </template>
    </sw-card>
</template>

<sw-agentic-commerce-tracking-config
    v-if="shouldRenderAgenticUi"
    :sales-channel="salesChannel"
    :disabled="!acl.can('sales_channel.editor') || undefined"
    @change="onTrackingConfigChange"
/>
{% endblock %}

{% block sw_sales_channel_detail_base_general_input_product_comparison_template %}
<sw-select-field
    v-if="isProductComparison"
    v-model="templateName"
    data-ac-template-select
    :disabled="!acl.can('sales_channel.editor')"
    :label="$t('sw-sales-channel.detail.productComparison.templates.label')"
    :placeholder="templateSelectPlaceholder"
    :options="templateSelectOptions"
    @change="$emit('template-selected', $event)"
    @update:value="(templateName) => $emit('template-selected', templateName)"
>
    <option
        v-for="option in templateSelectOptions"
        :key="option.id"
        :value="option.value"
    >
        {{ option.label }}
    </option>
</sw-select-field>
{% endblock %}

{% block sw_sales_channel_detail_base_general_input_product_comparison_filename %}
<template v-if="!isAgenticCommerce">
    {% parent %}
</template>
{% endblock %}

{% block sw_sales_channel_detail_base_general_input_product_comparison_encoding %}
<template v-if="!isAgenticCommerce">
    {% parent %}
</template>
{% endblock %}

{% block sw_sales_channel_detail_base_general_input_product_comparison_file_format %}
<template v-if="!isAgenticCommerce">
    {% parent %}
</template>
{% endblock %}

{% block sw_sales_channel_detail_base_options_api %}
<template v-if="!isAgenticCommerce">
    {% parent %}
</template>
{% endblock %}

{% block sw_sales_channel_shipping_payment %}
<template v-if="!isAgenticCommerce">
    {% parent %}
</template>
{% endblock %}
`;var{Component:Pe,Defaults:S}=Shopware,Ne=Shopware.Utils.object,De={template:B,inject:{swSalesChannelDetailGetAgenticCommerceExportConfig:{from:"swSalesChannelDetailGetAgenticCommerceExportConfig",default:()=>[]}},props:{agenticCommerceExportConfig:{type:Array,required:!1,default:()=>[]}},data(){return{}},computed:{isAgenticCommerce(){return this.salesChannel&&this.salesChannel.typeId===S.agenticCommerceTypeId},coreShipsAgenticCommerce(){return c},shouldRenderAgenticUi(){return this.isAgenticCommerce&&!this.coreShipsAgenticCommerce},isProductComparison(){return this.isAgenticCommerce?!0:!!this.salesChannel&&this.salesChannel.typeId===S.productComparisonTypeId},resolvedAgenticCommerceExportConfig(){let e=[];if(Array.isArray(this.agenticCommerceExportConfig)&&this.agenticCommerceExportConfig.length>0?e=this.agenticCommerceExportConfig:typeof this.swSalesChannelDetailGetAgenticCommerceExportConfig=="function"&&(e=this.swSalesChannelDetailGetAgenticCommerceExportConfig()??[]),e.length===0)return[];let t=this.productExport?.provider||e[0]?.provider,a=e.filter(i=>i.provider===t);return a.length>0?a:[e[0]]},templateSelectOptions(){return this.templateOptions.filter(e=>this.isAgenticCommerce?e.salesChannelTypeId===S.agenticCommerceTypeId:!e.salesChannelTypeId).map(e=>({value:e.name,id:e.name,label:this.$t(e.translationKey)}))},templateSelectPlaceholder(){return this.isAgenticCommerce&&this.productExport?.isNew()?null:this.$t("sw-sales-channel.detail.productComparison.templates.placeholderSelectTemplate")},disabledCountries(){return this.isAgenticCommerce?[]:this.salesChannel?.countries?.filter(e=>e.active===!1)??[]},unservedLanguages(){return this.isAgenticCommerce?[]:this.salesChannel?.languages?.filter(e=>(this.salesChannel.domains?.filter(t=>t.languageId===e.id)||[]).length===0)??[]}},methods:{getAgenticCommerceExportElementBind(e){let t=Ne.deepCopyObject(e);return["single-select","multi-select"].includes(t.type)&&(t.config.labelProperty="name",t.config.valueProperty="id"),t.type==="text-editor"&&(t.config.componentName="sw-text-editor"),t},getAgenticCommerceExportCardTitle(e){return e?.titleSnippet?this.$t(e.titleSnippet):e?.provider??""},getAgenticCommerceExportCardPositionIdentifier(e){return e?.positionIdentifier?e.positionIdentifier:"sw-sales-channel-detail-base-agentic-commerce-export-config-provider"},onAgenticCommerceExportFieldUpdate(e,t,a){a instanceof Event||(e.values[t]=a,e.errors?.[t]&&delete e.errors[t])},onTrackingConfigChange(e){this.salesChannel.configuration=e}}};Pe.override("sw-sales-channel-detail-base",De);var{Component:Re}=Shopware,$e=Shopware.Utils;Re.override("sw-sales-channel-create-base",{methods:{createdComponent(){this.onGenerateKeys(),this.isProductComparison&&this.onGenerateProductExportKey(!1),this.isAgenticCommerce&&this.prefillAgenticCommerceDefaults()},prefillAgenticCommerceDefaults(){this.productExport.fileName=`agentic-commerce-${$e.createId()}.jsonl`,this.productExport.provider="open-ai",this.productExport.encoding="UTF-8",this.productExport.generateByCronjob=!1,this.productExport.interval=86400}}});var j=`{% block sw_sales_channel_detail_agentic_commerce %}
<div class="sw-sales-channel-detail-agentic-commerce">

    {% block sw_sales_channel_detail_agentic_commerce_loading %}
    <sw-skeleton v-if="isUcpLoading" />
    {% endblock %}

    {# Tab content renders inside the page's own sw-card-view (core template),
       so we must NOT nest another one here \u2014 a second sw-card-view is
       position:absolute with its own 40px padding and shifts the cards. #}
    <template v-else>
        {% block sw_sales_channel_detail_agentic_commerce_unsaved_banner %}
        {# Always-visible unsaved-changes notice \u2014 visible from any sub-tab. #}
        <mt-banner
            v-if="isDirty"
            class="sw-sales-channel-detail-agentic-commerce__unsaved-banner"
            variant="attention"
            :title="$t('sw-sales-channel.detail.agenticCommerce.ucp.unsavedTitle')"
        >
            {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.unsavedMessage') }}
        </mt-banner>
        {% endblock %}

        {% block sw_sales_channel_detail_agentic_commerce_ucp_card %}
        <mt-card
            v-if="isTransactionalSalesChannel"
            class="sw-sales-channel-detail-agentic-commerce__ucp-card"
            position-identifier="sw-sales-channel-detail-agentic-commerce-ucp"
        >
            {% block sw_sales_channel_detail_agentic_commerce_ucp_title %}
            <template #title>
                <div class="sw-sales-channel-detail-agentic-commerce__card-title">
                    <h3 class="sw-sales-channel-detail-agentic-commerce__card-headline">
                        {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.cardTitle') }}
                    </h3>

                    <p class="sw-sales-channel-detail-agentic-commerce__card-description">
                        {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.cardIntro') }}
                    </p>
                </div>
            </template>
            {% endblock %}

            {% block sw_sales_channel_detail_agentic_commerce_ucp_subtabs %}
            <template #tabs>
                <mt-tabs
                    :items="subTabItems"
                    :default-item="resolvedSubTab"
                    @new-item-active="setSubTab"
                />
            </template>
            {% endblock %}

            {# ---- Exposure ---- #}
            {% block sw_sales_channel_detail_agentic_commerce_ucp_exposure %}
            <template v-if="resolvedSubTab === 'exposure'">
                <div class="sw-sales-channel-detail-agentic-commerce__section">
                    <mt-switch
                        class="sw-sales-channel-detail-agentic-commerce__expose-switch"
                        :label="$t('sw-sales-channel.detail.agenticCommerce.ucp.exposeLabel')"
                        :disabled="!canEditConfig"
                        :checked="isActive"
                        @change="setActive"
                    />
                </div>

                <div class="sw-sales-channel-detail-agentic-commerce__section">
                    <p class="sw-sales-channel-detail-agentic-commerce__section-label">
                        {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.profileDomainLabel') }}
                    </p>
                    <mt-select
                        v-if="hasMultipleDomains"
                        :options="profileDomainOptions"
                        :disabled="!canEditConfig"
                        :model-value="selectedProfileDomain"
                        @update:model-value="(value) => setValue('profileDomain', value)"
                    />
                    <p v-else class="sw-sales-channel-detail-agentic-commerce__hint">
                        {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.profileDomainSingle') }}
                    </p>
                </div>

                <div class="sw-sales-channel-detail-agentic-commerce__section">
                    <div class="sw-sales-channel-detail-agentic-commerce__option-grid">
                        <div class="sw-sales-channel-detail-agentic-commerce__option-column">
                            <p class="sw-sales-channel-detail-agentic-commerce__section-label">
                                {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.capabilitiesLabel') }}
                            </p>
                            <div class="sw-sales-channel-detail-agentic-commerce__option-list">
                                <mt-checkbox
                                    v-for="capability in readyCapabilities"
                                    :key="capability.value"
                                    class="sw-sales-channel-detail-agentic-commerce__option-item"
                                    :label="$t(capability.label)"
                                    :help-text="$t(capability.tooltip)"
                                    :disabled="!canEditConfig || !isActive"
                                    :checked="isCapabilityEnabled(capability.value)"
                                    @update:checked="(checked) => updateCapability(capability.value, checked)"
                                />
                            </div>
                        </div>

                        {% block sw_sales_channel_detail_agentic_commerce_ucp_transports %}
                        <div class="sw-sales-channel-detail-agentic-commerce__option-column">
                            <p class="sw-sales-channel-detail-agentic-commerce__section-label">
                                {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.transportsLabel') }}
                            </p>
                            <div class="sw-sales-channel-detail-agentic-commerce__option-list">
                                <mt-checkbox
                                    v-for="transport in transportItems"
                                    :key="transport.value"
                                    class="sw-sales-channel-detail-agentic-commerce__option-item"
                                    :label="transport.label"
                                    :help-text="$t(transport.description)"
                                    :disabled="!canEditConfig || !isActive"
                                    :checked="isTransportEnabled(transport.value)"
                                    @update:checked="(checked) => updateTransport(transport.value, checked)"
                                />
                            </div>
                        </div>
                        {% endblock %}
                    </div>
                </div>
            </template>
            {% endblock %}

            {# ---- Preview ---- #}
            {% block sw_sales_channel_detail_agentic_commerce_ucp_preview %}
            <template v-if="resolvedSubTab === 'preview'">
                <div class="sw-sales-channel-detail-agentic-commerce__section">
                    <p class="sw-sales-channel-detail-agentic-commerce__section-label">
                        {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.previewLabel') }}
                    </p>
                    <pre class="sw-sales-channel-detail-agentic-commerce__preview">{{ previewJson }}</pre>
                </div>
            </template>
            {% endblock %}
        </mt-card>
        {% endblock %}

        {% block sw_sales_channel_detail_agentic_commerce_export_card %}
        {# Product feed export \u2014 only relevant for Agentic Commerce channels. #}
        <mt-card
            v-if="isAgenticCommerce"
            position-identifier="sw-sales-channel-detail-agentic-commerce-export"
            :title="$t('sw-sales-channel.detail.agenticCommerce.exportCardTitle')"
        >
            <sw-sales-channel-detail-agentic-commerce-integration
                :sales-channel="salesChannel"
                :product-export="productExport"
                :product-comparison-access-url="productComparisonAccessUrl"
                :is-loading="isLoading"
            />
        </mt-card>
        {% endblock %}

        {% block sw_sales_channel_detail_agentic_commerce_files_card %}
        {# Reuse the core Agentic files management component (it ships its own
           card + File/Status/Description table + pagination) when core
           provides it. Older lanes have no useful Agentic files surface here. #}
        <sw-sales-channel-detail-agentic-files
            v-if="coreAgenticFilesAvailable"
            :sales-channel="salesChannel"
        />
        {% endblock %}
    </template>
</div>
{% endblock %}
`;var H=`{% block sw_sales_channel_detail_agentic_commerce %}
{# Legacy (Shopware 6.5) layout: stacked sw-card form, no in-card sub-tabs. #}
<div class="sw-sales-channel-detail-agentic-commerce">
    <sw-skeleton v-if="isUcpLoading" />

    <template v-else>
        <sw-alert v-if="isDirty" variant="warning">
            {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.unsavedMessage') }}
        </sw-alert>

        <sw-card
            v-if="isTransactionalSalesChannel"
            position-identifier="sw-sales-channel-detail-agentic-commerce-ucp"
            :title="$t('sw-sales-channel.detail.agenticCommerce.ucp.cardTitle')"
            :subtitle="$t('sw-sales-channel.detail.agenticCommerce.ucp.cardIntro')"
        >
            <sw-switch-field
                :label="$t('sw-sales-channel.detail.agenticCommerce.ucp.exposeLabel')"
                :disabled="!canEditConfig"
                :value="isActive"
                @change="setActive"
            />

            <sw-single-select
                v-if="hasMultipleDomains"
                :label="$t('sw-sales-channel.detail.agenticCommerce.ucp.profileDomainLabel')"
                :options="profileDomainOptions"
                :disabled="!canEditConfig"
                :value="form.profileDomain"
                @change="(value) => setValue('profileDomain', value)"
            />

            <div class="sw-sales-channel-detail-agentic-commerce__section">
                <div class="sw-sales-channel-detail-agentic-commerce__option-grid">
                    <div class="sw-sales-channel-detail-agentic-commerce__option-column">
                        <p class="sw-sales-channel-detail-agentic-commerce__section-label">
                            {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.capabilitiesLabel') }}
                        </p>
                        <div class="sw-sales-channel-detail-agentic-commerce__option-list">
                            <sw-checkbox-field
                                v-for="capability in readyCapabilities"
                                :key="capability.value"
                                class="sw-sales-channel-detail-agentic-commerce__option-item"
                                :label="$t(capability.label)"
                                :disabled="!canEditConfig || !isActive"
                                :value="isCapabilityEnabled(capability.value)"
                                @change="(checked) => updateCapability(capability.value, checked)"
                            />
                        </div>
                    </div>

                    <div class="sw-sales-channel-detail-agentic-commerce__option-column">
                        <p class="sw-sales-channel-detail-agentic-commerce__section-label">
                            {{ $t('sw-sales-channel.detail.agenticCommerce.ucp.transportsLabel') }}
                        </p>
                        <div class="sw-sales-channel-detail-agentic-commerce__option-list">
                            <sw-checkbox-field
                                v-for="transport in transportItems"
                                :key="transport.value"
                                class="sw-sales-channel-detail-agentic-commerce__option-item"
                                :label="transport.label"
                                :disabled="!canEditConfig || !isActive"
                                :value="isTransportEnabled(transport.value)"
                                @change="(checked) => updateTransport(transport.value, checked)"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </sw-card>

        <sw-card
            v-if="isTransactionalSalesChannel"
            position-identifier="sw-sales-channel-detail-agentic-commerce-preview"
            :title="$t('sw-sales-channel.detail.agenticCommerce.ucp.previewLabel')"
        >
            <pre class="sw-sales-channel-detail-agentic-commerce__preview">{{ previewJson }}</pre>
        </sw-card>

        <sw-card
            v-if="isAgenticCommerce"
            position-identifier="sw-sales-channel-detail-agentic-commerce-export"
            :title="$t('sw-sales-channel.detail.agenticCommerce.exportCardTitle')"
        >
            <sw-sales-channel-detail-agentic-commerce-integration
                :sales-channel="salesChannel"
                :product-export="productExport"
                :product-comparison-access-url="productComparisonAccessUrl"
                :is-loading="isLoading"
            />
        </sw-card>

        <sw-sales-channel-detail-agentic-files
            v-if="coreAgenticFilesAvailable"
            :sales-channel="salesChannel"
        />
    </template>
</div>
{% endblock %}
`;function Le(e){if(typeof e!="string")return null;let t=e.match(/^(\d+)\.(\d+)/);return t?{major:Number(t[1]),minor:Number(t[2])}:null}function k(e,t,a){let i=Le(e);return i?i.major!==t?i.major>t:i.minor>=a:!1}function A(){return Shopware?.Context?.app?.config?.version??""}function q(e=A()){return k(e,6,6)}var K="sw-sales-channel.detail.agenticCommerce.ucp",v="exposure",Me="preview",w=v,J=[{name:v,label:`${K}.subTabExposure`},{name:Me,label:`${K}.subTabPreview`}];function W(e,{active:t=!0}={}){let a=J.map(i=>({name:i.name,label:typeof e=="function"?e(i.label):i.label,disabled:!t&&i.name!==v}));return k(A(),6,7)?a:a.filter(i=>!i.disabled)}function Y(e,{active:t=!0}={}){return!J.some(a=>a.name===e)||!t&&e!==v?w:e}var o="sw-sales-channel.detail.agenticCommerce.ucp",X=[{value:"catalog",label:`${o}.capabilityCatalogLabel`,tooltip:`${o}.capabilityCatalogTooltip`},{value:"cart",label:`${o}.capabilityCartLabel`,tooltip:`${o}.capabilityCartTooltip`},{value:"discount",label:`${o}.capabilityDiscountLabel`,tooltip:`${o}.capabilityDiscountTooltip`},{value:"checkout",label:`${o}.capabilityCheckoutLabel`,tooltip:`${o}.capabilityCheckoutTooltip`},{value:"order",label:`${o}.capabilityOrderLabel`,tooltip:`${o}.capabilityOrderTooltip`}],Xt=[{value:"identity_linking",label:`${o}.capabilityIdentityLinkingLabel`,reason:`${o}.capabilityIdentityLinkingReason`,docsUrl:"https://developer.shopware.com/docs/concepts/agentic-commerce/ucp.html"},{value:"payment_tokenization",label:`${o}.capabilityPaymentTokenizationLabel`,reason:`${o}.capabilityPaymentTokenizationReason`,docsUrl:"https://developer.shopware.com/docs/concepts/agentic-commerce/ucp.html"}];var Fe=[{value:"rest",label:"REST",description:"sw-sales-channel.detail.agenticCommerce.ucp.transportRestDescription"},{value:"a2a",label:"A2A",description:"sw-sales-channel.detail.agenticCommerce.ucp.transportA2aDescription"},{value:"embedded",label:"Embedded",description:"sw-sales-channel.detail.agenticCommerce.ucp.transportEmbeddedDescription"},{value:"mcp",label:"MCP",description:"sw-sales-channel.detail.agenticCommerce.ucp.transportMcpDescription",requiresStoreApiMcp:!0}];function Q(e={}){return Fe.filter(t=>!t.requiresStoreApiMcp||e.supportsStoreApiMcp===!0)}function ee(e,t){return Z(e)!==Z(t)}function Z(e){return JSON.stringify(e,ze)}function ze(e,t){return t&&typeof t=="object"&&!Array.isArray(t)?Object.keys(t).sort().reduce((a,i)=>(a[i]=t[i],a),{}):t}var{Mixin:Ge,Defaults:Be}=Shopware;d("sw-sales-channel-detail-agentic-commerce",{template:q()?j:H,inject:["ucpAdminApiService","acl","swSalesChannelGetUcpState"],mixins:[Ge.getByName("notification")],props:{salesChannel:{required:!0},productExport:{required:!1,default:null},productComparisonAccessUrl:{type:String,default:""},isLoading:{type:Boolean,default:!1}},data(){return{activeSubTab:w}},watch:{form:{deep:!0,handler(){this.scheduleEditedPreview()}}},beforeUnmount(){window.clearTimeout(this.previewTimer)},computed:{ucpState(){return this.swSalesChannelGetUcpState()},form(){return this.ucpState.form},meta(){return this.ucpState.meta??{}},preview(){return this.ucpState.preview},isUcpLoading(){return this.ucpState.isLoading||this.isLoading},canEditConfig(){return this.acl.can("ucp.editor")},isActive(){return!!this.form?.active},isDirty(){return ee(this.ucpState.savedForm,this.form)},subTabItems(){return W(e=>this.$t(e),{active:this.isActive})},resolvedSubTab(){return Y(this.activeSubTab,{active:this.isActive})},isAgenticCommerce(){return this.salesChannel?.typeId===Be.agenticCommerceTypeId},isTransactionalSalesChannel(){return _(this.salesChannel?.typeId)},coreAgenticFilesAvailable(){return!!Shopware?.Component?.getComponentRegistry?.().has("sw-sales-channel-detail-agentic-files")},readyCapabilities(){return X},transportItems(){return Q(this.meta)},profileDomainOptions(){return(Array.isArray(this.salesChannel?.domains)?this.salesChannel.domains:[]).map(t=>({value:t.url,label:t.url})).filter(t=>t.value)},hasMultipleDomains(){return this.profileDomainOptions.length>1},selectedProfileDomain(){return this.form?.profileDomain||this.profileDomainOptions[0]?.value||""},previewJson(){return this.preview?JSON.stringify(this.preview,null,2):""}},methods:{setSubTab(e){this.activeSubTab=e},scheduleEditedPreview(){this.salesChannel?.id&&(window.clearTimeout(this.previewTimer),this.previewTimer=window.setTimeout(()=>this.refreshEditedPreview(),400))},refreshEditedPreview(){this.salesChannel?.id&&this.ucpAdminApiService.previewConfig(this.salesChannel.id,p(this.form)).then(e=>{this.ucpState.preview=e.data.data??null}).catch(()=>{})},setActive(e){e instanceof Event||(this.form.active=!!e)},setValue(e,t){t instanceof Event||(this.form[e]=t)},updateCapability(e,t){t instanceof Event||(this.form.enabledCapabilities=y(this.form.enabledCapabilities,e,t))},isCapabilityEnabled(e){return this.form.enabledCapabilities.includes(e)},updateTransport(e,t){t instanceof Event||(this.form.enabledTransports=y(this.form.enabledTransports,e,t))},isTransportEnabled(e){return this.form.enabledTransports.includes(e)}}});var te=`{% block sw_sales_channel_detail_agentic_commerce_integration %}
<div class="sw-sales-channel-detail-agentic-commerce-integration">
    {% block sw_sales_channel_detail_agentic_commerce_integration_card %}
    <sw-card
        v-if="!isLoading"
        position-identifier="sw-sales-channel-detail-agentic-commerce-integration"
        :is-loading="isLoading"
        :title="$t(integrationSnippetPrefix + '.cardTitle')"
    >
        <sw-container>
            {% block sw_sales_channel_detail_agentic_commerce_integration_introduction %}
            <div class="sw-sales-channel-detail-agentic-commerce-integration__introduction">
                <h6 class="sw-sales-channel-detail-agentic-commerce-integration__introduction-title">
                    {{ $t(integrationSnippetPrefix + '.introduction.title') }}
                </h6>

                <p class="sw-sales-channel-detail-agentic-commerce-integration__introduction-description">
                    {{ $t(integrationSnippetPrefix + '.introduction.description') }}
                </p>
            </div>
            {% endblock %}

            {% block sw_sales_channel_detail_agentic_commerce_integration_steps %}
            <div class="sw-sales-channel-detail-agentic-commerce-integration__step-by-step">
                <h6 class="sw-sales-channel-detail-agentic-commerce-integration__step-by-step-title">
                    {{ $t('sw-sales-channel.detail.agenticCommerce.integration.stepByStepTitle') }}
                </h6>

                <ol
                    class="sw-sales-channel-detail-agentic-commerce-integration__step-by-step-list"
                    v-html="$t(integrationSnippetPrefix + '.stepByStep')"
                ></ol>
            </div>
            {% endblock %}

            {% block sw_sales_channel_detail_agentic_commerce_integration_feed_url %}
            <sw-text-field
                v-if="feedUrl"
                :value="feedUrl"
                :label="$t('sw-sales-channel.detail.agenticCommerce.integration.feedUrlLabel')"
                :disabled="true"
                :copyable="true"
            />
            {% endblock %}
        </sw-container>
    </sw-card>
    {% endblock %}
</div>
{% endblock %}
`;var ae="open-ai";d("sw-sales-channel-detail-agentic-commerce-integration",{template:te,inject:["acl"],props:{salesChannel:{required:!0},productExport:{required:!0},productComparisonAccessUrl:{type:String,default:""},isLoading:{type:Boolean,default:!1}},computed:{providerName(){return this.productExport?.provider||ae},isOpenAi(){return this.providerName===ae},feedUrl(){return this.productComparisonAccessUrl||""},integrationSnippetPrefix(){return`sw-sales-channel.detail.agenticCommerce.integration.providers.${this.providerName}`}}});var ie=`{% block sw_sales_channel_detail_agentic_commerce_statistics %}
<sw-container class="sw-sales-channel-detail-agentic-commerce-statistics">

    {% block sw_sales_channel_detail_agentic_commerce_statistics_orders_card %}
    <template v-if="acl.can('order.viewer')">
        <div>
            <sw-chart-card
                class="sw-sales-channel-detail-agentic-commerce-statistics__orders"
                :card-subtitle="getChartRangeSubtitle(statisticDateRangesOrderCount)"
                :series="orderCountSeries"
                :options="chartOptionsOrderCount"
                :fill-empty-values="getTimeUnitInterval(statisticDateRangesOrderCount)"
                type="line"
                position-identifier="sw-sales-channel-detail-agentic-commerce-statistics__orders"
                sort
                @sw-chart-card-range-update="onOrderCountRangeUpdate"
            >
                <template #header-title>
                    {{ $t('sw-sales-channel.detail.productExport.insights.orderCardHeadline') }}
                </template>

                <template #range-option="{ range }">
                    {{ $t(\`sw-sales-channel.detail.productExport.insights.dateRanges.\${range}\`) }}
                </template>

                <template #default>
                    <p class="sw-sales-channel-detail-agentic-commerce-statistics__summary">
                        {{ $t('sw-sales-channel.detail.productExport.insights.summary') }}:
                        <strong>{{ orderCountTotal }}</strong>
                    </p>
                </template>
            </sw-chart-card>
        </div>
    </template>
    {% endblock %}

    {% block sw_sales_channel_detail_agentic_commerce_statistics_customers_card %}
    <template v-if="acl.can('customer.viewer')">
        <div>
            <sw-chart-card
                class="sw-sales-channel-detail-agentic-commerce-statistics__customers"
                :card-subtitle="getChartRangeSubtitle(statisticDateRangesCustomerCount)"
                :series="customerCountSeries"
                :options="chartOptionsCustomerCount"
                :fill-empty-values="getTimeUnitInterval(statisticDateRangesCustomerCount)"
                type="line"
                position-identifier="sw-sales-channel-detail-agentic-commerce-statistics__customers"
                sort
                @sw-chart-card-range-update="onCustomerCountRangeUpdate"
            >
                <template #header-title>
                    {{ $t('sw-sales-channel.detail.productExport.insights.customerCardHeadline') }}
                </template>

                <template #range-option="{ range }">
                    {{ $t(\`sw-sales-channel.detail.productExport.insights.dateRanges.\${range}\`) }}
                </template>

                <template #default>
                    <p class="sw-sales-channel-detail-agentic-commerce-statistics__summary">
                        {{ $t('sw-sales-channel.detail.productExport.insights.summary') }}:
                        <strong>{{ customerCountTotal }}</strong>
                    </p>
                </template>
            </sw-chart-card>
        </div>
    </template>
    {% endblock %}

    {% block sw_sales_channel_detail_agentic_commerce_statistics_turnover_card %}
    <template v-if="acl.can('order.viewer')">
        <div>
            <sw-chart-card
                class="sw-sales-channel-detail-agentic-commerce-statistics__turnover"
                :card-subtitle="getChartRangeSubtitle(statisticDateRangesOrderSum)"
                :series="orderSumSeries"
                :options="chartOptionsOrderSum"
                :fill-empty-values="getTimeUnitInterval(statisticDateRangesOrderSum)"
                type="line"
                position-identifier="sw-sales-channel-detail-agentic-commerce-statistics__turnover"
                sort
                @sw-chart-card-range-update="onOrderSumRangeUpdate"
            >
                <template #header-title>
                    {{ $t('sw-sales-channel.detail.productExport.insights.turnoverCardHeadline') }}
                </template>

                <template #range-option="{ range }">
                    {{ $t(\`sw-sales-channel.detail.productExport.insights.dateRanges.\${range}\`) }}
                </template>

                <template #default>
                    <p class="sw-sales-channel-detail-agentic-commerce-statistics__summary">
                        {{ $t('sw-sales-channel.detail.productExport.insights.summary') }}:
                        <strong>{{ currencyFilter(orderSumTotal, systemCurrencyISOCode, 2) }}</strong>
                    </p>
                </template>
            </sw-chart-card>
        </div>
    </template>
    {% endblock %}
</sw-container>
{% endblock %}`;var{Criteria:l}=Shopware.Data,T={"180Days":180,"30Days":30,"14Days":14,"7Days":7,"24Hours":24,yesterday:1};d("sw-sales-channel-detail-agentic-commerce-statistics",{template:ie,inject:["repositoryFactory","acl"],props:{salesChannel:{required:!0}},data(){return{historyOrderDataCount:[],historyOrderDataSum:[],historyCustomerDataCount:[],statisticDateRangesOrderCount:{value:"30Days",options:T},statisticDateRangesOrderSum:{value:"30Days",options:T},statisticDateRangesCustomerCount:{value:"30Days",options:T},isLoading:!0}},computed:{orderRepository(){return this.repositoryFactory.create("order")},customerRepository(){return this.repositoryFactory.create("customer")},currencyFilter(){return Shopware.Filter.getByName("currency")},systemCurrencyISOCode(){return Shopware.Context.app.systemCurrencyISOCode},today(){let e=Shopware.Utils.format.dateWithUserTimezone();return e.setHours(0,0,0,0),e},orderCountCriteria(){let e=new l(1,500);return e.addFilter(l.equals("salesChannelTracking.salesChannelId",this.salesChannel.id)),e.addFilter(l.range("orderDate",{gte:this.formatDate(this.dateAgoValue(this.statisticDateRangesOrderCount))})),e.addSorting(l.sort("orderDateTime","DESC")),e},orderSumCriteria(){let e=new l(1,500);return e.addFilter(l.equals("salesChannelTracking.salesChannelId",this.salesChannel.id)),e.addFilter(l.equals("transactions.stateMachineState.technicalName","paid")),e.addFilter(l.range("orderDate",{gte:this.formatDate(this.dateAgoValue(this.statisticDateRangesOrderSum))})),e.addSorting(l.sort("orderDateTime","DESC")),e},customerCountCriteria(){let e=new l(1,500);return e.addFilter(l.equals("salesChannelTracking.salesChannelId",this.salesChannel.id)),e.addFilter(l.range("createdAt",{gte:this.formatDate(this.dateAgoValue(this.statisticDateRangesCustomerCount))})),e.addSorting(l.sort("createdAt","DESC")),e},chartOptionsOrderCount(){return this.buildCountChartOptions(this.statisticDateRangesOrderCount)},chartOptionsCustomerCount(){return this.buildCountChartOptions(this.statisticDateRangesCustomerCount)},chartOptionsOrderSum(){return{xaxis:{type:"datetime",min:this.dateAgoValue(this.statisticDateRangesOrderSum).getTime(),labels:{datetimeUTC:!1}},yaxis:{min:0,tickAmount:5,labels:{formatter:e=>this.currencyFilter(e,this.systemCurrencyISOCode,2)}},tooltip:{x:{format:this._tooltipFormat(this.statisticDateRangesOrderSum)}}}},orderCountSeries(){let e=this.aggregateCount(this.historyOrderDataCount,"orderDateTime",this.statisticDateRangesOrderCount);return e.length===0?[]:[{name:this.$t("sw-sales-channel.detail.productExport.insights.numbers"),data:e}]},customerCountSeries(){let e=this.aggregateCount(this.historyCustomerDataCount,"createdAt",this.statisticDateRangesCustomerCount);return e.length===0?[]:[{name:this.$t("sw-sales-channel.detail.productExport.insights.numbers"),data:e}]},orderSumSeries(){let e=this.aggregateTurnover(this.historyOrderDataSum,this.statisticDateRangesOrderSum);return e.length===0?[]:[{name:this.$t("sw-sales-channel.detail.productExport.insights.totalTurnover"),data:e}]},orderCountTotal(){return this.historyOrderDataCount.length},customerCountTotal(){return this.historyCustomerDataCount.length},orderSumTotal(){return this.historyOrderDataSum.reduce((e,t)=>e+(t.amountTotal??0),0)}},created(){this.fetchData()},methods:{async fetchData(){this.isLoading=!0;let e=[];this.acl.can("order.viewer")&&e.push(this.loadHistoryOrderCount(),this.loadHistoryOrderSum()),this.acl.can("customer.viewer")&&e.push(this.loadHistoryCustomerCount());try{await Promise.allSettled(e)}finally{this.isLoading=!1}},loadHistoryOrderCount(){return this.orderRepository.search(this.orderCountCriteria).then(e=>{this.historyOrderDataCount=e})},loadHistoryOrderSum(){return this.orderRepository.search(this.orderSumCriteria).then(e=>{this.historyOrderDataSum=e})},loadHistoryCustomerCount(){return this.customerRepository.search(this.customerCountCriteria).then(e=>{this.historyCustomerDataCount=e})},onOrderCountRangeUpdate(e){this.statisticDateRangesOrderCount.value=e,this.loadHistoryOrderCount()},onOrderSumRangeUpdate(e){this.statisticDateRangesOrderSum.value=e,this.loadHistoryOrderSum()},onCustomerCountRangeUpdate(e){this.statisticDateRangesCustomerCount.value=e,this.loadHistoryCustomerCount()},buildCountChartOptions(e){return{xaxis:{type:"datetime",min:this.dateAgoValue(e).getTime(),labels:{datetimeUTC:!1}},yaxis:{min:0,tickAmount:3,labels:{formatter:t=>parseInt(t,10)}},tooltip:{x:{format:this._tooltipFormat(e)}}}},aggregateCount(e,t,a){let i=this.getTimeUnitInterval(a)==="hour",n=e.reduce((s,r)=>this._bucketRow(s,r[t],i,r),{});return Object.entries(n).map(([s,r])=>({x:parseInt(s,10),y:r.length}))},aggregateTurnover(e,t){let a=this.getTimeUnitInterval(t)==="hour",i=e.reduce((n,s)=>this._bucketRow(n,s.orderDateTime,a,s),{});return Object.entries(i).map(([n,s])=>({x:parseInt(n,10),y:s.reduce((r,m)=>r+(m.amountTotal??0),0)}))},dateAgoValue(e){let t=Shopware.Utils.format.dateWithUserTimezone(),a=e.options[e.value]??0;return e.value==="24Hours"?(t.setHours(t.getHours()-a),t):(t.setDate(t.getDate()-a),t.setHours(0,0,0,0),t)},getTimeUnitInterval(e){return e.value==="yesterday"||e.value==="24Hours"?"hour":"day"},getChartRangeSubtitle(e){return`${this.formatChartHeadlineDate(this.dateAgoValue(e))}-${this.formatChartHeadlineDate(this.today)}`},formatDate(e){return Shopware.Utils.format.toISODate(e,!1)},formatChartHeadlineDate(e){let t=Shopware.Application.getContainer("factory").locale.getLastKnownLocale();return e.toLocaleDateString(t,{day:"numeric",month:"short"})},_bucketRow(e,t,a,i){let n=this._bucketKey(t,a);return n===null||(e[n]||(e[n]=[]),e[n].push(i)),e},_bucketKey(e,t){if(!e)return null;let a=e.match(/^(?<date>\d{4}-\d{2}-\d{2})T(?<hour>\d{2}):(?<minSec>\d{2}:\d{2})(?:\.(?<ms>\d{1,3}))?(?<trail>.*)$/);if(a===null)return null;let i=t?e.replace(a[0],`${a.groups.date}T${a.groups.hour}:00:00.000${a.groups.trail}`):e.replace(a[0],`${a.groups.date}T00:00:00.000${a.groups.trail}`);return Shopware.Utils.format.dateWithUserTimezone(new Date(i)).getTime()},_tooltipFormat(e){return this.getTimeUnitInterval(e)==="hour"?"dd MMM HH:mm":"dd MMM"}}});var ne=`{% block sw_sales_channel_detail_product_comparison_actions_preview_component %}
<sw-button
    v-if="shouldRenderAgenticUi"
    size="small"
    variant="secondary"
    class="sw-sales-channel-detail-product-comparison__reset-action"
    :is-loading="isLoadingReset"
    :disabled="isLoadingReset || !acl.can('sales_channel.editor')"
    @click="resetToDefault"
>
    {{ $t('sw-sales-channel.detail.agenticCommerce.buttonResetTemplate') }}
</sw-button>

{% parent %}
{% endblock %}
`;var{Component:Ke,Defaults:Je}=Shopware;Ke.override("sw-sales-channel-detail-product-comparison",{template:ne,data(){return{isLoadingReset:!1}},computed:{isAgenticCommerce(){return this.salesChannel?.typeId===Je.agenticCommerceTypeId},shouldRenderAgenticUi(){return this.isAgenticCommerce&&!c}},methods:{resetToDefault(){let e=this.productExport.provider||"open-ai",t=Shopware.Service("exportTemplateService").getProductExportTemplateRegistry(),a=Object.values(t).find(i=>i.providerName===e);if(!a){this.createNotificationError({message:this.$t("sw-sales-channel.detail.agenticCommerce.errorLoadingTemplate")});return}this.productExport.headerTemplate=a.headerTemplate,this.productExport.bodyTemplate=a.bodyTemplate,this.productExport.footerTemplate=a.footerTemplate,this.createNotificationInfo({message:this.$t("sw-sales-channel.detail.agenticCommerce.resetTemplateSuccess")})}}});var We="sw-sales-channel-detail-agentic-commerce",Ye="sw-sales-channel-detail-agentic-commerce-statistics";function se(e){return async()=>await Shopware.Component.build(e)}var re=[{name:"sw.sales.channel.detail.agenticCommerce",path:"agentic-commerce",component:se(We),isChildren:!0,meta:{parentPath:"sw.sales.channel.list",privilege:"ucp.viewer"}},{name:"sw.sales.channel.detail.agenticCommerceStatistics",path:"agentic-commerce-statistics",component:se(Ye),isChildren:!0,meta:{parentPath:"sw.sales.channel.list",privilege:"ucp.viewer"}}],Xe=Shopware.Module.getModuleRegistry(),E=Xe.get("sw-sales-channel");if(E){let e=E.routes.get("sw.sales.channel.detail");if(e&&((e.children===void 0||e.children===null)&&(e.children=[]),Array.isArray(e.children))){let t=e.children.map(a=>a.name);re.forEach(a=>{t.includes(a.name)||(e.children.push(a),E.routes.set(a.name,a))})}}Shopware.Application.viewInitialized.then(()=>{let e=Shopware.Application.view?.router;e&&(typeof e.hasRoute!="function"||typeof e.addRoute!="function"||re.forEach(t=>{e.hasRoute(t.name)||e.addRoute("sw.sales.channel.detail",{name:t.name,path:t.path,component:t.component,meta:t.meta})}))});c||Promise.resolve().then(()=>(de(),et));})();
