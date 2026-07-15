import type {
  SubscriberArgs,
  SubscriberConfig,
} from "@medusajs/framework"
import { HttpTypes } from "@medusajs/types"

/**
 * 📦 Order Created Subscriber
 * Syncs new orders to Olist/Tiny ERP when order is placed
 * CRITICAL: Without this, orders don't reach the supplier
 */

async function sendOrderToOlist(
  order: HttpTypes.AdminOrder,
  olistToken: string
) {
  try {
    const orderData = {
      order_number: order.display_id || order.id,
      customer_email: order.email,
      customer_name: order.billing_address?.first_name || "Customer",
      customer_phone: order.billing_address?.phone || "",
      total: (order.total || 0) / 100, // Convert cents to decimal
      items: (order.items || []).map((item: any) => ({
        product_id: item.product_id,
        sku: item.variant?.sku,
        quantity: item.quantity,
        price: (item.unit_price || 0) / 100,
      })),
      shipping_address: {
        street: order.shipping_address?.address_1 || "",
        city: order.shipping_address?.city || "",
        state: order.shipping_address?.province || "",
        zip_code: order.shipping_address?.postal_code || "",
        country: order.shipping_address?.country_code || "BR",
      },
      payment_method: order.payment_collections?.[0]?.payments?.[0]?.provider_id || "manual",
      status: "pending_fulfillment",
      notes: `Medusa Order ID: ${order.id}`,
    }

    // Send to Olist API
    const response = await fetch("https://api.olist.com/v1/orders", {
      method: "POST",
      headers: {
        "Authorization": `Bearer ${olistToken}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify(orderData),
    })

    if (!response.ok) {
      const error = await response.text()
      throw new Error(`Olist API error: ${response.status} - ${error}`)
    }

    const result = await response.json()
    console.log(`✅ Order ${order.id} synced to Olist as ${result.id}`)

    return {
      success: true,
      olist_order_id: result.id,
      medusa_order_id: order.id,
    }
  } catch (error) {
    console.error(`❌ Failed to sync order ${order.id} to Olist:`, error)
    return {
      success: false,
      error: String(error),
      medusa_order_id: order.id,
    }
  }
}

/**
 * Main subscriber handler
 * Triggered when order.created event fires
 */
export default async function orderCreatedHandler({
  event: { data },
  container,
}: SubscriberArgs<{ id: string }>) {
  const orderId = data.id

  // Get the order service
  const orderModuleService = container.resolve("order")

  try {
    // Retrieve full order details
    const order = await orderModuleService.retrieveOrder(orderId, {
      relations: [
        "items",
        "items.variant",
        "items.product",
        "billing_address",
        "shipping_address",
        "payment_collections",
        "payment_collections.payments",
      ],
    })

    console.log(`📦 Processing new order: ${orderId}`)

    // Get Olist token from environment
    const olistToken = process.env.OLIST_ACCESS_TOKEN
    if (!olistToken) {
      console.warn(`⚠️  OLIST_ACCESS_TOKEN not configured - order won't sync to ERP!`)
      return {
        success: false,
        error: "OLIST_ACCESS_TOKEN not configured",
      }
    }

    // Send order to Olist
    const syncResult = await sendOrderToOlist(order, olistToken)

    if (syncResult.success) {
      // Update order metadata with Olist ID for future reference
      await orderModuleService.updateOrders(
        { id: orderId },
        { metadata: { olist_order_id: syncResult.olist_order_id } }
      )
      console.log(`✅ Order ${orderId} successfully synced to Olist`)
    } else {
      console.error(`❌ Failed to sync order ${orderId} to Olist`, syncResult.error)
      // Log this failure for monitoring
      // TODO: Add alerting for failed syncs
    }

    return syncResult
  } catch (error) {
    console.error(`❌ Error processing order ${orderId}:`, error)
    return {
      success: false,
      error: String(error),
    }
  }
}

// Subscriber configuration - listen for order.created event
export const config: SubscriberConfig = {
  event: "order.created",
}
