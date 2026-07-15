import { MedusaContainer } from "@medusajs/framework";
import {
  ContainerRegistrationKeys,
  ProductStatus,
} from "@medusajs/framework/utils";
import {
  createCustomersWorkflow,
  createProductsWorkflow,
  createRegionsWorkflow,
  createShippingOptionsWorkflow,
  createStockLocationsWorkflow,
  createTaxRegionsWorkflow,
  linkSalesChannelsToStockLocationWorkflow,
  updateStoresWorkflow,
} from "@medusajs/medusa/core-flows";
import { Modules } from "@medusajs/framework/utils";

export default async function seedShopVivalizTestData({
  container,
}: {
  container: MedusaContainer;
}) {
  const logger = container.resolve(ContainerRegistrationKeys.LOGGER);
  const link = container.resolve(ContainerRegistrationKeys.LINK);
  const query = container.resolve(ContainerRegistrationKeys.QUERY);
  const fulfillmentModuleService = container.resolve(Modules.FULFILLMENT);

  const { data: stores } = await query.graph({
    entity: "store",
    fields: [
      "id",
      "supported_currencies.currency_code",
      "supported_currencies.is_default",
      "default_sales_channel_id",
    ],
  });
  const store = stores[0];

  const { data: salesChannels } = await query.graph({
    entity: "sales_channel",
    fields: ["id", "name"],
  });
  const defaultSalesChannel =
    salesChannels.find((sc) => sc.id === store.default_sales_channel_id) ||
    salesChannels[0];

  const { data: shippingProfiles } = await query.graph({
    entity: "shipping_profile",
    fields: ["id"],
  });
  const shippingProfile = shippingProfiles[0];

  const hasBrl = store.supported_currencies?.some(
    (c: any) => c.currency_code === "brl"
  );

  if (!hasBrl) {
    logger.info("Adding BRL as a supported currency...");
    await updateStoresWorkflow(container).run({
      input: {
        selector: { id: store.id },
        update: {
          supported_currencies: [
            ...store.supported_currencies.map((c: any) => ({
              currency_code: c.currency_code,
              is_default: c.is_default,
            })),
            { currency_code: "brl", is_default: false },
          ],
        },
      },
    });
  }

  const { data: existingRegions } = await query.graph({
    entity: "region",
    fields: ["id", "name", "currency_code"],
  });
  let brRegion: { id: string } | undefined = existingRegions.find(
    (r) => r.currency_code === "brl"
  );

  if (!brRegion) {
    logger.info("Seeding Brazil region...");
    const { result: regionResult } = await createRegionsWorkflow(container).run({
      input: {
        regions: [
          {
            name: "Brazil",
            currency_code: "brl",
            countries: ["br"],
            payment_providers: ["pp_system_default"],
          },
        ],
      },
    });
    brRegion = { id: regionResult[0].id };

    await createTaxRegionsWorkflow(container).run({
      input: [{ country_code: "br", provider_id: "tp_system" }],
    });

    logger.info("Seeding Brazil warehouse + shipping...");
    const { result: stockLocationResult } = await createStockLocationsWorkflow(
      container
    ).run({
      input: {
        locations: [
          {
            name: "Depósito Brasil",
            address: {
              city: "São Paulo",
              country_code: "BR",
              address_1: "",
            },
          },
        ],
      },
    });
    const brStockLocation = stockLocationResult[0];

    await link.create({
      [Modules.STOCK_LOCATION]: { stock_location_id: brStockLocation.id },
      [Modules.FULFILLMENT]: { fulfillment_provider_id: "manual_manual" },
    });

    const brFulfillmentSet = await fulfillmentModuleService.createFulfillmentSets({
      name: "Entrega Brasil",
      type: "shipping",
      service_zones: [
        {
          name: "Brasil",
          geo_zones: [{ country_code: "br", type: "country" }],
        },
      ],
    });

    await link.create({
      [Modules.STOCK_LOCATION]: { stock_location_id: brStockLocation.id },
      [Modules.FULFILLMENT]: { fulfillment_set_id: brFulfillmentSet.id },
    });

    await createShippingOptionsWorkflow(container).run({
      input: [
        {
          name: "Frete Padrão",
          price_type: "flat",
          provider_id: "manual_manual",
          service_zone_id: brFulfillmentSet.service_zones[0].id,
          shipping_profile_id: shippingProfile.id,
          type: {
            label: "Padrão",
            description: "Entrega em 5-7 dias úteis.",
            code: "standard",
          },
          prices: [
            { currency_code: "brl", amount: 25 },
            { currency_code: "usd", amount: 5 },
            { region_id: brRegion.id, amount: 25 },
          ],
          rules: [
            { attribute: "enabled_in_store", value: "true", operator: "eq" },
            { attribute: "is_return", value: "false", operator: "eq" },
          ],
        },
      ],
    });

    await linkSalesChannelsToStockLocationWorkflow(container).run({
      input: { id: brStockLocation.id, add: [defaultSalesChannel.id] },
    });

    logger.info("Finished seeding Brazil region/shipping.");
  } else {
    logger.info("Brazil region already exists, skipping.");
  }

  const testProducts = [
    {
      title: "Camiseta ShopVivaliz",
      handle: "camiseta-shopvivaliz",
      description:
        "Camiseta 100% algodão, corte unissex, confortável para o dia a dia.",
      sku: "TSHIRT-001",
      image: "https://placehold.co/800x800/c0392b/ffffff/png?text=Camiseta",
      weight: 200,
      priceBRL: 69.9,
      priceUSD: 13.9,
    },
    {
      title: "Calça Jeans ShopVivaliz",
      handle: "calca-jeans-shopvivaliz",
      description:
        "Calça jeans de corte reto, tecido resistente e confortável para o dia a dia.",
      sku: "JEANS-001",
      image: "https://placehold.co/800x800/2c3e50/ffffff/png?text=Cal%C3%A7a+Jeans",
      weight: 700,
      priceBRL: 149.9,
      priceUSD: 29.9,
    },
    {
      title: "Tênis Casual ShopVivaliz",
      handle: "tenis-casual-shopvivaliz",
      description:
        "Tênis casual leve e confortável, ideal para o uso diário.",
      sku: "SHOES-001",
      image: "https://placehold.co/800x800/34495e/ffffff/png?text=T%C3%AAnis",
      weight: 900,
      priceBRL: 199.9,
      priceUSD: 39.9,
    },
    {
      title: "Boné ShopVivaliz",
      handle: "bone-shopvivaliz",
      description: "Boné ajustável em algodão, protege do sol com estilo.",
      sku: "HAT-001",
      image: "https://placehold.co/800x800/8e44ad/ffffff/png?text=Bon%C3%A9",
      weight: 150,
      priceBRL: 59.9,
      priceUSD: 11.9,
    },
    {
      title: "Jaqueta ShopVivaliz",
      handle: "jaqueta-shopvivaliz",
      description:
        "Jaqueta corta-vento com forro leve, perfeita para dias mais frios.",
      sku: "JACKET-001",
      image: "https://placehold.co/800x800/16a085/ffffff/png?text=Jaqueta",
      weight: 800,
      priceBRL: 249.9,
      priceUSD: 49.9,
    },
    {
      title: "Vestido ShopVivaliz",
      handle: "vestido-shopvivaliz",
      description:
        "Vestido midi em viscose leve, corte solto e caimento fluido.",
      sku: "DRESS-001",
      image: "https://placehold.co/800x800/e67e22/ffffff/png?text=Vestido",
      weight: 300,
      priceBRL: 179.9,
      priceUSD: 35.9,
    },
    {
      title: "Bermuda ShopVivaliz",
      handle: "bermuda-shopvivaliz",
      description:
        "Bermuda de sarja com elastano, confortável para o verão.",
      sku: "SHORTS-001",
      image: "https://placehold.co/800x800/27ae60/ffffff/png?text=Bermuda",
      weight: 350,
      priceBRL: 99.9,
      priceUSD: 19.9,
    },
    {
      title: "Mochila ShopVivaliz",
      handle: "mochila-shopvivaliz",
      description:
        "Mochila impermeável com compartimento acolchoado para notebook.",
      sku: "BACKPACK-001",
      image: "https://placehold.co/800x800/2980b9/ffffff/png?text=Mochila",
      weight: 600,
      priceBRL: 159.9,
      priceUSD: 31.9,
    },
  ];

  const { data: existingProducts } = await query.graph({
    entity: "product",
    fields: ["id", "handle"],
  });
  const existingHandles = new Set(existingProducts.map((p) => p.handle));

  const productsToCreate = testProducts.filter(
    (p) => !existingHandles.has(p.handle)
  );

  if (productsToCreate.length > 0) {
    logger.info(`Seeding ${productsToCreate.length} ShopVivaliz test products...`);
    await createProductsWorkflow(container).run({
      input: {
        products: productsToCreate.map((p) => ({
          title: p.title,
          handle: p.handle,
          description: p.description,
          status: ProductStatus.PUBLISHED,
          weight: p.weight,
          shipping_profile_id: shippingProfile.id,
          images: [{ url: p.image }],
          options: [{ title: "Tamanho", values: ["Único"] }],
          variants: [
            {
              title: "Único",
              sku: p.sku,
              options: { Tamanho: "Único" },
              prices: [
                { amount: p.priceBRL, currency_code: "brl" },
                { amount: p.priceUSD, currency_code: "usd" },
              ],
            },
          ],
          sales_channels: [{ id: defaultSalesChannel.id }],
        })),
      },
    });

    const { data: inventoryItems } = await query.graph({
      entity: "inventory_item",
      fields: ["id", "sku"],
    });
    const { data: stockLocations } = await query.graph({
      entity: "stock_location",
      fields: ["id"],
    });

    const newSkus = new Set(productsToCreate.map((p) => p.sku));
    const newInventoryItems = inventoryItems.filter(
      (i) => i.sku && newSkus.has(i.sku)
    );

    const { createInventoryLevelsWorkflow } = await import(
      "@medusajs/medusa/core-flows"
    );
    await createInventoryLevelsWorkflow(container).run({
      input: {
        inventory_levels: newInventoryItems.flatMap((item) =>
          stockLocations.map((loc) => ({
            location_id: loc.id,
            stocked_quantity: 1000,
            inventory_item_id: item.id,
          }))
        ),
      },
    });

    logger.info("Finished seeding ShopVivaliz test products.");
  } else {
    logger.info("ShopVivaliz test products already exist, skipping.");
  }

  const testCustomerEmail = "cliente.teste@shopvivaliz.com.br";
  const { data: existingCustomers } = await query.graph({
    entity: "customer",
    fields: ["id", "email"],
    filters: { email: testCustomerEmail },
  });

  if (!existingCustomers.length) {
    logger.info("Seeding test customer...");
    await createCustomersWorkflow(container).run({
      input: {
        customersData: [
          {
            email: testCustomerEmail,
            first_name: "Cliente",
            last_name: "Teste",
            has_account: false,
          },
        ],
      },
    });
    logger.info("Finished seeding test customer.");
  } else {
    logger.info("Test customer already exists, skipping.");
  }
}
