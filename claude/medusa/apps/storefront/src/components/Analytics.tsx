'use client'

import { useEffect } from 'react'
import { usePathname, useSearchParams } from 'next/navigation'

/**
 * 📊 Google Analytics 4 Component
 * Handles GA4 tracking, ecommerce events, conversions
 */

export function GoogleAnalytics() {
  const pathname = usePathname()
  const searchParams = useSearchParams()

  useEffect(() => {
    // Initialize GA4 if not already initialized
    if (typeof window !== 'undefined' && !window.dataLayer) {
      window.dataLayer = window.dataLayer || []
      function gtag(...args: any[]) {
        window.dataLayer.push(arguments)
      }
      window.gtag = gtag
      gtag('js', new Date())

      const GA4_ID = process.env.NEXT_PUBLIC_GA_MEASUREMENT_ID
      if (GA4_ID) {
        gtag('config', GA4_ID, {
          page_path: pathname,
          page_title: document.title,
        })
      }
    }

    // Track page view on route change
    if (window.gtag) {
      window.gtag('event', 'page_view', {
        page_path: pathname,
        page_title: document.title,
        page_location: window.location.href,
      })
    }
  }, [pathname, searchParams])

  const GA4_ID = process.env.NEXT_PUBLIC_GA_MEASUREMENT_ID

  if (!GA4_ID) {
    return null
  }

  return (
    <>
      {/* Google Analytics 4 gtag.js */}
      <script
        async
        src={`https://www.googletagmanager.com/gtag/js?id=${GA4_ID}`}
      />
      <script
        dangerouslySetInnerHTML={{
          __html: `
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '${GA4_ID}', {
              'page_path': window.location.pathname,
              'anonymize_ip': true,
            });
          `,
        }}
      />
    </>
  )
}

/**
 * Track events helper functions
 */

export const trackEvent = (eventName: string, params: Record<string, any> = {}) => {
  if (typeof window !== 'undefined' && window.gtag) {
    window.gtag('event', eventName, params)
  }
}

export const trackViewItem = (product: {
  id: string
  name: string
  price: number
  category?: string
  brand?: string
}) => {
  trackEvent('view_item', {
    currency: 'BRL',
    value: product.price,
    items: [
      {
        item_id: product.id,
        item_name: product.name,
        item_brand: product.brand || 'ShopVivaliz',
        item_category: product.category || 'General',
        price: product.price,
      },
    ],
  })
}

export const trackAddToCart = (product: {
  id: string
  name: string
  price: number
  quantity: number
}) => {
  trackEvent('add_to_cart', {
    currency: 'BRL',
    value: product.price * product.quantity,
    items: [
      {
        item_id: product.id,
        item_name: product.name,
        quantity: product.quantity,
        price: product.price,
      },
    ],
  })
}

export const trackBeginCheckout = (items: any[], cartValue: number) => {
  trackEvent('begin_checkout', {
    currency: 'BRL',
    value: cartValue,
    items: items,
  })
}

export const trackPurchase = (order: {
  id: string
  total: number
  tax?: number
  shipping?: number
  items: any[]
}) => {
  trackEvent('purchase', {
    transaction_id: order.id,
    currency: 'BRL',
    value: order.total,
    tax: order.tax || 0,
    shipping: order.shipping || 0,
    items: order.items,
  })

  // Also fire Google Ads conversion if configured
  const GOOGLE_ADS_CONVERSION_ID = process.env.NEXT_PUBLIC_GOOGLE_ADS_CONVERSION_ID
  const GOOGLE_ADS_CONVERSION_LABEL = process.env.NEXT_PUBLIC_GOOGLE_ADS_CONVERSION_LABEL_PURCHASE

  if (GOOGLE_ADS_CONVERSION_ID && GOOGLE_ADS_CONVERSION_LABEL && window.gtag) {
    window.gtag('event', 'conversion', {
      'send_to': `${GOOGLE_ADS_CONVERSION_ID}/${GOOGLE_ADS_CONVERSION_LABEL}`,
      'value': order.total,
      'currency': 'BRL',
      'transaction_id': order.id,
    })
  }
}

// TypeScript global declaration
declare global {
  interface Window {
    dataLayer: any[]
    gtag: (...args: any[]) => void
  }
}
