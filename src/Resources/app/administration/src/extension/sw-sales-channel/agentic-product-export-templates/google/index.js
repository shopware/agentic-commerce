import header from './header.xml.twig.js';
import body from './body.xml.twig.js';
import footer from './footer.xml.twig.js';

Shopware.Service('exportTemplateService').registerProductExportTemplate({
    name: 'google',
    translationKey: 'sw-sales-channel.detail.agenticCommerce.templates.template-label.google',
    salesChannelTypeId: Shopware.Defaults.agenticCommerceTypeId,
    providerName: 'google',
    headerTemplate: header.trim(),
    bodyTemplate: body,
    footerTemplate: footer.trim(),
    encoding: 'UTF-8',
    fileFormat: 'xml',
    generateByCronjob: false,
    interval: 86400,
});
