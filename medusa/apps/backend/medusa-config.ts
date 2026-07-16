import { loadEnv, defineConfig } from '@medusajs/framework/utils'
import { dirname } from 'node:path'

const backendRoot = dirname(new URL(import.meta.url).pathname)

loadEnv(process.env.NODE_ENV || 'development', backendRoot)

const resolveDatabaseUrl = () => {
  if (process.env.DATABASE_URL) {
    return process.env.DATABASE_URL
  }

  const host = process.env.MEDUSA_DB_HOST || process.env.DB_HOST
  const user = process.env.MEDUSA_DB_USER || process.env.DB_USER
  const password = process.env.MEDUSA_DB_PASS || process.env.DB_PASS
  const database = process.env.MEDUSA_DB_NAME || 'shopvivaliz_medusa'
  const port = process.env.MEDUSA_DB_PORT || '5432'

  if (!host || !user) {
    return undefined
  }

  return `postgres://${user}:${password || ''}@${host}:${port}/${database}`
}

module.exports = defineConfig({
  projectConfig: {
    databaseUrl: resolveDatabaseUrl(),
    redisUrl: process.env.REDIS_URL,
    http: {
      storeCors: process.env.STORE_CORS!,
      adminCors: process.env.ADMIN_CORS!,
      authCors: process.env.AUTH_CORS!,
      jwtSecret: process.env.JWT_SECRET,
      cookieSecret: process.env.COOKIE_SECRET,
    }
  }
})
