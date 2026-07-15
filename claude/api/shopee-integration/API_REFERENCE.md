# 📡 Shopee API Reference

## Authentication

### Get Access Token
Exchange authorization code for access_token and refresh_token.

**Endpoint**: `POST /api/v2/auth/token/get`

**Query Parameters**:
```
- partner_id: 1237032
- timestamp: Unix timestamp
- sign: HMAC-SHA256 signature
```

**Request Body**:
```json
{
  "code": "authorization_code",
  "shop_id": 227695582,
  "partner_id": 1237032
}
```

**Response**:
```json
{
  "access_token": "xxxxx",
  "refresh_token": "xxxxx",
  "expire_in": 14400,
  "request_id": "xxxxx",
  "merchant_id_list": [],
  "shop_id_list": [227695582],
  "supplier_id_list": [],
  "user_id_list": [5835321926],
  "error": "",
  "message": ""
}
```

### Refresh Token
Renew access_token before expiration.

**Endpoint**: `POST /api/v2/auth/token/refresh`

**Query Parameters**:
```
- partner_id: 1237032
- timestamp: Unix timestamp
- sign: HMAC-SHA256 signature
```

**Request Body**:
```json
{
  "refresh_token": "xxxxx",
  "shop_id": 227695582,
  "partner_id": 1237032
}
```

---

## Shop Endpoints

### Get Shop Info
Get information about your Shopee shop.

**Endpoint**: `GET /api/v2/shop/get_shop_info`

**Query Parameters**:
```
- partner_id: 1237032
- timestamp: Unix timestamp
- sign: HMAC-SHA256 signature
- access_token: xxxxx
- shop_id: 227695582
```

**Response**:
```json
{
  "data": {
    "shop_id": 227695582,
    "shop_name": "ShopVivaliz Test",
    "shop_logo": "https://...",
    "shop_cover": "https://...",
    "shop_category": 1,
    "shop_status": "active",
    "shop_rating": 5.0,
    "shop_feedback_rate": 0.0
  },
  "request_id": "xxxxx",
  "error": "",
  "message": ""
}
```

---

## Product Endpoints

### Search Products
Search for products in your shop.

**Endpoint**: `GET /api/v2/product/search_product`

**Query Parameters**:
```
- partner_id: 1237032
- timestamp: Unix timestamp
- sign: HMAC-SHA256 signature
- access_token: xxxxx
- shop_id: 227695582
- cursor: (optional) pagination cursor
- page_size: (optional) items per page (default: 10, max: 100)
- item_status: (optional) NORMAL, BANNED, DELETED, EXPIRED
- sort_by: (optional) create_time, update_time, sales
- order: (optional) asc, desc
```

**Response**:
```json
{
  "data": {
    "item_list": [
      {
        "item_id": 123456,
        "item_name": "Product Name",
        "item_sku": "SKU123",
        "item_status": "NORMAL",
        "create_time": 1234567890,
        "update_time": 1234567890,
        "price": 99.99,
        "image": "https://..."
      }
    ],
    "cursor": "next_page_cursor",
    "has_more": true,
    "total": 150
  },
  "request_id": "xxxxx"
}
```

### Get Categories
Get available product categories.

**Endpoint**: `GET /api/v2/product/get_categories`

**Query Parameters**:
```
- partner_id: 1237032
- timestamp: Unix timestamp
- sign: HMAC-SHA256 signature
- access_token: xxxxx
- shop_id: 227695582
- region_code: (optional) BR, SG, etc
```

**Response**:
```json
{
  "data": {
    "category_list": [
      {
        "category_id": 100,
        "category_name": "Category Name",
        "parent_category_id": 0
      }
    ]
  },
  "request_id": "xxxxx"
}
```

### Add Product
Add a new product to your shop.

**Endpoint**: `POST /api/v2/product/add`

**Query Parameters**:
```
- partner_id: 1237032
- timestamp: Unix timestamp
- sign: HMAC-SHA256 signature
- access_token: xxxxx
- shop_id: 227695582
```

**Request Body**:
```json
{
  "item_name": "Product Name",
  "category_id": 100,
  "description": "Product description",
  "price": 99.99,
  "item_sku": "SKU123",
  "quantity": 100,
  "images": [
    {
      "image_url": "https://..."
    }
  ]
}
```

### Update Product
Update an existing product.

**Endpoint**: `POST /api/v2/product/update`

**Query Parameters**:
```
- partner_id: 1237032
- timestamp: Unix timestamp
- sign: HMAC-SHA256 signature
- access_token: xxxxx
- shop_id: 227695582
```

**Request Body**:
```json
{
  "item_id": 123456,
  "item_name": "Updated Name",
  "price": 79.99,
  "quantity": 50
}
```

### Delete Product
Delete a product from your shop.

**Endpoint**: `POST /api/v2/product/delete`

**Query Parameters**:
```
- partner_id: 1237032
- timestamp: Unix timestamp
- sign: HMAC-SHA256 signature
- access_token: xxxxx
- shop_id: 227695582
```

**Request Body**:
```json
{
  "item_id": 123456
}
```

---

## Image Upload

### Upload Product Image
Upload images for a product.

**Endpoint**: `POST /api/v2/product/img/upload`

**Query Parameters**:
```
- partner_id: 1237032
- timestamp: Unix timestamp
- sign: HMAC-SHA256 signature
- access_token: xxxxx
- shop_id: 227695582
```

**Request Body** (multipart/form-data):
```
- image: [binary image file]
- item_id: 123456 (optional)
```

**Response**:
```json
{
  "data": {
    "upload_id": "xxxxx",
    "image_url": "https://..."
  },
  "request_id": "xxxxx"
}
```

---

## Signature Generation

All requests require HMAC-SHA256 signature.

### Python Example
```python
import hmac
import hashlib
import time

PARTNER_ID = 1237032
PARTNER_KEY = "shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d"
path = "/api/v2/shop/get_shop_info"
timestamp = int(time.time())

# Generate signature
base = f"{PARTNER_ID}{path}{timestamp}"
sign = hmac.new(
    PARTNER_KEY.encode(),
    base.encode(),
    hashlib.sha256
).hexdigest()

# Build URL
url = (
    f"https://openplatform.sandbox.test-stable.shopee.sg{path}"
    f"?partner_id={PARTNER_ID}"
    f"&timestamp={timestamp}"
    f"&sign={sign}"
    f"&access_token=YOUR_ACCESS_TOKEN"
    f"&shop_id=227695582"
)
```

---

## Error Codes

| Error Code | Description |
|-----------|-------------|
| error_sign | Invalid signature |
| error_param | Missing or invalid parameter |
| error_auth | Authentication failed |
| error_access_denied | Access denied for this operation |
| error_rate_limit | Rate limit exceeded |
| error_not_found | Resource not found |
| error_server | Server error |

### Error Response Example
```json
{
  "error": "error_param",
  "message": "Shop ID is required",
  "request_id": "xxxxx"
}
```

---

## Rate Limits

- Requests per minute: 60
- Concurrent requests: 10
- Retry after: Check `Retry-After` header

---

## Best Practices

1. **Caching**: Cache shop info and categories locally
2. **Pagination**: Use cursor for large product lists
3. **Batch Operations**: Use batch endpoints when available
4. **Error Handling**: Implement retry logic with exponential backoff
5. **Token Refresh**: Refresh token 1 hour before expiration
6. **Logging**: Log all API calls for debugging

---

**Last Updated**: 2026-06-29  
**API Version**: v2  
**Documentation**: https://open.shopee.com/
