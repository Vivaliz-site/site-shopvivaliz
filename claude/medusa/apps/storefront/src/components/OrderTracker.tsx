'use client'

import { useEffect } from 'react'
import { trackPurchase } from './Analytics'
import { HttpTypes } from '@medusajs/types'

/**
 * 📊 Order Tracker Component
 * Tracks purchase conversion when order confirmation page loads
 */

interface OrderTrackerProps {
  order: HttpTypes.StoreOrder
}

export function OrderTracker({ order }: OrderTrackerProps) {
  useEffect(() => {
    if (!order || !order.id) return

    // Format items for GA4
    const items = (order.items || []).map((item: any) => ({
      item_id: item.variant_id || item.product_id || item.id,
      item_name: item.title || item.product_title,
      item_category: item.product?.category_id || 'General',
      item_brand: 'ShopVivaliz',
      price: (item.unit_price || 0) / 100, // Convert cents to decimal
      quantity: item.quantity || 1,
    }))

    // Calculate totals (convert from cents)
    const subtotal = (order.subtotal || 0) / 100
    const tax = (order.tax_total || 0) / 100
    const shipping = (order.shipping_total || 0) / 100
    const total = (order.total || 0) / 100

    // Track purchase
    trackPurchase({
      id: order.id,
      total: total,
      tax: tax,
      shipping: shipping,
      items: items,
    })

    console.log('✅ Purchase tracked:', {
      order_id: order.id,
      total: total,
      items: items.length,
    })
  }, [order])

  return null
}
