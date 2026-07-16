import { loadEnv, defineConfig } from '@medusajs/framework/utils'

loadEnv(process.env.NODE_ENV || 'development', process.cwd())

module.exports = defineConfig({
  projectConfig: {
    databaseUrl: process.env.DATABASE_URL,
    http: {
      storeCors: process.env.STORE_CORS!,
      adminCors: process.env.ADMIN_CORS!,
      authCors: process.env.AUTH_CORS!,
      jwtSecret: process.env.JWT_SECRET,
      cookieSecret: process.env.COOKIE_SECRET,
    }
  },
  modules: process.env.STRIPE_API_KEY
    ? [
        {
          resolve: "@medusajs/medusa/payment",
          options: {
            providers: [
              {
                resolve: "@medusajs/payment-stripe",
                id: "stripe",
                options: {
                  apiKey: process.env.STRIPE_API_KEY,
                  webhookSecret: process.env.STRIPE_WEBHOOK_SECRET,
                  // PIX: enviar payment_method_types: ["pix"] ao criar o
                  // PaymentIntent (Stripe BR). Ver claude/medusa/DEPLOY-CHECKLIST.md.
                  capture: true,
                },
              },
            ],
          },
        },
      ]
    : [],
})
