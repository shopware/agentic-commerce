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
