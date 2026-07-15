import { MedusaRequest, MedusaResponse } from "@medusajs/framework/http"
import { ContainerRegistrationKeys } from "@medusajs/framework/utils"
import {
  updateInventoryLevelsWorkflow,
  updateProductVariantsWorkflow,
} from "@medusajs/medusa/core-flows"
import crypto from "crypto"

/**
 * Incoming webhook from the Olist ERP (claude/api/sync-olist-products.php /
 * claude/api/olist/auto-sync.php) to push price/stock updates for a single
 * SKU into the Medusa catalog. Configure the same shared secret as
 * OLIST_WEBHOOK_SECRET on both sides; requests are rejected if the
 * X-Olist-Signature header doesn't match the HMAC-SHA256 of the raw body.
 *
 * Body: { sku: string, preco_venda?: number, estoque_atual?: number }
 */
export async function POST(req: MedusaRequest, res: MedusaResponse) {
  const logger = req.scope.resolve(ContainerRegistrationKeys.LOGGER)
  const webhookSecret = process.env.OLIST_WEBHOOK_SECRET

  if (webhookSecret) {
    const rawBody = (req as any).rawBody ?? JSON.stringify(req.body)
    const receivedSignature = req.headers["x-olist-signature"] as string | undefined
    const expectedSignature = crypto
      .createHmac("sha256", webhookSecret)
      .update(rawBody)
      .digest("hex")

    if (!receivedSignature || receivedSignature !== expectedSignature) {
      logger.warn("Olist webhook: assinatura invalida ou ausente")
      return res.status(401).json({ error: "Assinatura invalida" })
    }
  }

  const { sku, preco_venda, estoque_atual } = req.body as {
    sku?: string
    preco_venda?: number
    estoque_atual?: number
  }

  if (!sku) {
    return res.status(400).json({ error: "Campo 'sku' obrigatorio" })
  }

  const query = req.scope.resolve(ContainerRegistrationKeys.QUERY)

  const { data: variants } = await query.graph({
    entity: "variant",
    fields: ["id", "sku", "inventory_items.inventory.id"],
    filters: { sku },
  })

  const variant = variants[0]
  if (!variant) {
    return res.status(404).json({ error: `Variante com SKU ${sku} nao encontrada` })
  }

  if (typeof preco_venda === "number") {
    await updateProductVariantsWorkflow(req.scope).run({
      input: {
        product_variants: [
          {
            id: variant.id,
            prices: [{ amount: preco_venda, currency_code: "brl" }],
          },
        ],
      },
    })
  }

  if (typeof estoque_atual === "number") {
    const inventoryItemId = variant.inventory_items?.[0]?.inventory?.id

    if (inventoryItemId) {
      const { data: stockLocations } = await query.graph({
        entity: "stock_location",
        fields: ["id"],
      })
      const locationId = stockLocations[0]?.id

      if (locationId) {
        await updateInventoryLevelsWorkflow(req.scope).run({
          input: {
            updates: [
              {
                inventory_item_id: inventoryItemId,
                location_id: locationId,
                stocked_quantity: estoque_atual,
              },
            ],
          },
        })
      }
    }
  }

  logger.info(`Olist webhook: SKU ${sku} sincronizado`)
  res.status(200).json({ success: true, sku })
}
