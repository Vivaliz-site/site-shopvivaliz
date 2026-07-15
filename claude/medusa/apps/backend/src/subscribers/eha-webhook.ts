import type { SubscriberArgs, SubscriberConfig } from "@medusajs/framework"
import { ContainerRegistrationKeys } from "@medusajs/framework/utils"
import crypto from "crypto"

/**
 * Forwards commerce events to the EHA bridge (claude/api/medusa-webhook.php)
 * so the autonomous agent system can sync marketplaces / react to new data.
 * Config lives in EHA_WEBHOOK_URL / EHA_WEBHOOK_SECRET (see .env).
 */
async function forwardToEha({
  event,
  container,
  data,
}: {
  event: string
  container: SubscriberArgs<any>["container"]
  data: unknown
}) {
  const logger = container.resolve(ContainerRegistrationKeys.LOGGER)
  const webhookUrl = process.env.EHA_WEBHOOK_URL
  const webhookSecret = process.env.EHA_WEBHOOK_SECRET

  if (!webhookUrl) {
    return
  }

  const body = JSON.stringify({
    id: `evt_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`,
    type: event,
    data,
  })
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
  }

  if (webhookSecret) {
    headers["X-Medusa-Signature"] = crypto
      .createHmac("sha256", webhookSecret)
      .update(body)
      .digest("hex")
  }

  try {
    const response = await fetch(webhookUrl, {
      method: "POST",
      headers,
      body,
    })

    if (!response.ok) {
      logger.warn(
        `EHA webhook forward failed for ${event}: HTTP ${response.status}`
      )
    }
  } catch (error) {
    logger.warn(
      `EHA webhook forward errored for ${event}: ${(error as Error).message}`
    )
  }
}

export default async function ehaWebhookHandler({
  event: { name, data },
  container,
}: SubscriberArgs<{ id: string }>) {
  await forwardToEha({ event: name, container, data })
}

export const config: SubscriberConfig = {
  event: [
    "product.created",
    "product.updated",
    "order.placed",
    "customer.created",
  ],
}
