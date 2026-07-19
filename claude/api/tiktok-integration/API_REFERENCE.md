# 📡 TikTok Shop API Reference

## Base URLs

- **Sandbox**: https://open-api.tiktokglobalshop.com
- **Production**: https://open-api.tiktokshop.com

## Authentication

### OAuth 2.0 Flow

#### Step 1: Redirect to Authorization
```
GET https://partner.tiktokshop.com/authorize?
  client_id=YOUR_APP_KEY&
  redirect_uri=https://shopvivaliz.com.br&
  response_type=code&
  scope=shop.basic,product.read,product.write
```

**Required Parameters**:
- `client_id`: Your App Key (6kf502maarj2k)
- `redirect_uri`: Your Redirect URL
- `response_type`: "code"
- `scope`: Requested permissions

#### Step 2: Get Access Token
```
POST https://open-api.tiktokglobalshop.com/authorization/1.0/oauth2/token

Content-Type: application/json

{
  "client_id": "6kf502maarj2k",
  "client_secret": "f0a2a1e58a7le4ca8b5f0f7fdfdb2o0ebee06c",
  "code": "authorization_code_from_step_1",
  "grant_type": "authorization_code",
  "redirect_uri": "https://shopvivaliz.com.br"
}
```

**Response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "access_token": "xxxxx",
    "refresh_token": "xxxxx",
    "expires_in": 3600,
    "token_type": "Bearer"
  }
}
```

---

## Shop Endpoints

### Get Shop Info
Get information about your TikTok Shop.

**Endpoint**: `GET /shop/1.0/shop/get_shop_info`

**Headers**:
```
Authorization: Bearer {access_token}
Content-Type: application/json
```

**Response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "shop_id": "xxxxx",
    "shop_name": "Your Shop Name",
    "shop_status": "active",
    "region": "BR"
  }
}
```

### Get Base Info
Get base shop information.

**Endpoint**: `GET /shop/1.0/shop/get_base_info`

**Headers**:
```
Authorization: Bearer {access_token}
Content-Type: application/json
```

---

## Product Endpoints

### Search Products
Search for products in your shop.

**Endpoint**: `GET /product/202309/products/search`

**Query Parameters**:
```
shop_id: Your shop ID
page_size: Items per page (1-100)
cursor: Pagination cursor
status: Product status (ACTIVE, DRAFT, etc)
```

**Response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "products": [
      {
        "product_id": "123456",
        "product_name": "Product Name",
        "sku": "SKU123",
        "status": "ACTIVE",
        "price": 99.99
      }
    ],
    "cursor": "next_page_cursor"
  }
}
```

### Get Product
Get product details.

**Endpoint**: `GET /product/202309/products/{product_id}`

**Headers**:
```
Authorization: Bearer {access_token}
Content-Type: application/json
```

**Response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "product_id": "123456",
    "product_name": "Product Name",
    "category_id": "xxxxx",
    "sku": "SKU123",
    "price": 99.99,
    "stock": 100,
    "images": ["url1", "url2"],
    "description": "Product description"
  }
}
```

### Create Product
Create a new product.

**Endpoint**: `POST /product/202309/products/create`

**Headers**:
```
Authorization: Bearer {access_token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "product_name": "New Product",
  "category_id": "xxxxx",
  "description": "Product description",
  "skus": [
    {
      "sku": "SKU001",
      "price": 99.99,
      "stock": 100
    }
  ],
  "images": [
    {
      "url": "https://..."
    }
  ]
}
```

### Update Product
Update an existing product.

**Endpoint**: `POST /product/202309/products/update`

**Headers**:
```
Authorization: Bearer {access_token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "product_id": "123456",
  "product_name": "Updated Name",
  "price": 79.99,
  "stock": 50
}
```

### Delete Product
Delete a product.

**Endpoint**: `POST /product/202309/products/delete`

**Headers**:
```
Authorization: Bearer {access_token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "product_id": "123456"
}
```

---

## Token Management

### Refresh Token
Get a new access token using refresh token.

**Endpoint**: `POST /authorization/1.0/oauth2/token`

**Request Body**:
```json
{
  "client_id": "6kf502maarj2k",
  "client_secret": "f0a2a1e58a7le4ca8b5f0f7fdfdb2o0ebee06c",
  "grant_type": "refresh_token",
  "refresh_token": "your_refresh_token"
}
```

**Response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "access_token": "new_access_token",
    "refresh_token": "new_refresh_token",
    "expires_in": 3600
  }
}
```

---

## Error Codes

| Code | Message | Description |
|------|---------|-------------|
| 0 | success | Request succeeded |
| 401 | unauthorized | Invalid or expired token |
| 403 | forbidden | Permission denied |
| 404 | not_found | Resource not found |
| 429 | rate_limited | Rate limit exceeded |
| 500 | server_error | Server error |

### Error Response Example
```json
{
  "code": 401,
  "message": "unauthorized",
  "error": "Invalid access token"
}
```

---

## Rate Limits

- Requests per minute: 100
- Requests per hour: 5000
- Concurrent requests: 20

Check `X-RateLimit-*` headers in response for current limits.

---

## Best Practices

1. **Token Management**: Refresh token 1 hour before expiration
2. **Error Handling**: Implement retry logic with exponential backoff
3. **Pagination**: Use cursor for large product lists
4. **Caching**: Cache shop info locally
5. **Logging**: Log all API calls for debugging
6. **Security**: Store tokens securely in GitHub Secrets

---

**API Version**: v1.0  
**Documentation**: https://developers.tiktok.com/doc/  
**Last Updated**: 2026-06-29
