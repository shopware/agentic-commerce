/**
 * @sw-package discovery
 */

jest.mock(
  "./sw-sales-channel-detail.html.twig",
  () => "mock-template",
  { virtual: true }
);

function loadComputed(coreHasAgenticComponent) {
  let computed;

  jest.isolateModules(() => {
    global.Shopware = {
      Component: {
        override: jest.fn(),
        getComponentRegistry: () => ({ has: () => coreHasAgenticComponent }),
      },
      Utils: { object: {} },
      Classes: { ShopwareError: class {} },
      Context: { api: {} },
      Defaults: {},
    };

    computed =
      require("./index")
        .swSalesChannelDetailOverride.computed;
  });

  return computed;
}

describe("sw-sales-channel-detail agenticCommerceStatisticsRoute", () => {
  afterEach(() => {
    delete global.Shopware;
  });

  it("points at the plugin route where core has no agentic commerce admin", () => {
    expect(loadComputed(false).agenticCommerceStatisticsRoute.call({})).toBe(
      "sw.sales.channel.detail.agenticCommerceStatistics"
    );
  });

  it("points at the core route once core ships the agentic commerce admin", () => {
    expect(loadComputed(true).agenticCommerceStatisticsRoute.call({})).toBe(
      "sw.sales.channel.detail.productExportInsights"
    );
  });
});

describe("sw-sales-channel-detail Insights ACL", () => {
  const insight = { name: "sw.sales.channel.detail.productExportInsights" };
  const base = { name: "sw.sales.channel.detail.base" };
  const tabs = [base, insight];

  it("hides core Insights for Agentic Commerce without ucp.viewer", () => {
    const computed = loadComputed(true);
    const actual = computed.salesChannelDetailTabs.call({
      $super: () => tabs,
      isAgenticCommerce: true,
      canViewUcpInsights: false,
    });

    expect(actual).toEqual([base]);
  });

  it("preserves core Insights for Agentic Commerce with ucp.viewer", () => {
    const computed = loadComputed(true);
    const actual = computed.salesChannelDetailTabs.call({
      $super: () => tabs,
      isAgenticCommerce: true,
      canViewUcpInsights: true,
    });

    expect(actual).toEqual(tabs);
  });

  it("leaves product-comparison Insights unchanged", () => {
    const computed = loadComputed(true);
    const actual = computed.salesChannelDetailTabs.call({
      $super: () => tabs,
      isAgenticCommerce: false,
      canViewUcpInsights: false,
    });

    expect(actual).toBe(tabs);
  });
});
