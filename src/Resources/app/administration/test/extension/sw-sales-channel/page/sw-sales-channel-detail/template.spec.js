/**
 * @sw-package discovery
 */

const fs = require('fs');
const path = require('path');

const template = fs.readFileSync(
    path.resolve(__dirname, '../../../../../src/extension/sw-sales-channel/page/sw-sales-channel-detail/sw-sales-channel-detail.html.twig'),
    'utf8',
);

describe('sw-sales-channel-detail template override', () => {
    // Replacing this block drops the props other plugins pass to the routed view, e.g. Social Shopping's network form.
    it('leaves the routed content view to core', () => {
        expect(template).not.toContain('{% block sw_sales_channel_detail_content_view %}');
    });
});
