import PostalMime from "postal-mime";

function htmlToText(html) {
  return String(html || "")
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/gi, " ")
    .replace(/&amp;/gi, "&")
    .replace(/\s+/g, " ")
    .trim();
}

async function normalizedMessage(message) {
  const raw = await new Response(message.raw).arrayBuffer();
  const parsed = await new PostalMime().parse(raw);
  const rfcMessageId = String(parsed.messageId || message.headers.get("message-id") || "").trim();
  let messageId = rfcMessageId;
  if (!messageId) {
    const digest = await crypto.subtle.digest("SHA-256", raw);
    messageId = Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, "0")).join("");
  }
  const from = String(parsed.from?.address || message.from || "").trim();
  const subject = String(parsed.subject || message.headers.get("subject") || "").trim();
  const text = String(parsed.text || htmlToText(parsed.html) || "").trim();
  const date = parsed.date ? new Date(parsed.date) : new Date();
  return {
    message_id: messageId,
    rfc_message_id: rfcMessageId,
    from,
    subject,
    received_at: Number.isNaN(date.getTime()) ? new Date().toISOString() : date.toISOString(),
    body_text: text,
    snippet: text.slice(0, 500),
  };
}

export async function deliver(message, env) {
  const payload = await normalizedMessage(message);
  const response = await fetch(env.INGRESS_URL, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      "CF-Access-Client-Id": env.CF_ACCESS_CLIENT_ID,
      "CF-Access-Client-Secret": env.CF_ACCESS_CLIENT_SECRET,
    },
    body: JSON.stringify(payload),
  });
  if (!response.ok) {
    throw new Error(`Amazon Returns ingress rejected email with HTTP ${response.status}`);
  }
  await message.forward(env.FORWARD_TO);
}

export default {
  async email(message, env) {
    await deliver(message, env);
  },
};
