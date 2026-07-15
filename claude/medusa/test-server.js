#!/usr/bin/env node

/**
 * Mock Medusa API Server for Testing
 * Simula a API Medusa com dados de teste
 */

const http = require('http');
const url = require('url');

// Dados de teste
const testData = {
  products: [
    { id: 'prod_1', title: 'T-Shirt', description: 'Cotton T-Shirt', price: 29.99, images: [{ url: 'https://via.placeholder.com/300' }] },
    { id: 'prod_2', title: 'Jeans', description: 'Blue Jeans', price: 79.99, images: [{ url: 'https://via.placeholder.com/300' }] },
    { id: 'prod_3', title: 'Shoes', description: 'Running Shoes', price: 99.99, images: [{ url: 'https://via.placeholder.com/300' }] },
    { id: 'prod_4', title: 'Hat', description: 'Baseball Hat', price: 19.99, images: [{ url: 'https://via.placeholder.com/300' }] },
    { id: 'prod_5', title: 'Jacket', description: 'Winter Jacket', price: 149.99, images: [{ url: 'https://via.placeholder.com/300' }] },
  ],
  carts: {},
  orders: [],
};

const server = http.createServer((req, res) => {
  const parsedUrl = url.parse(req.url, true);
  const pathname = parsedUrl.pathname;
  const method = req.method;

  // CORS
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Content-Type', 'application/json');

  if (method === 'OPTIONS') {
    res.writeHead(200);
    res.end();
    return;
  }

  // Health check
  if (pathname === '/health') {
    res.writeHead(200);
    res.end(JSON.stringify({ status: 'ok' }));
    return;
  }

  // Admin auth
  if (pathname === '/admin/auth/login' && method === 'POST') {
    res.writeHead(200);
    res.end(JSON.stringify({
      access_token: 'test_token_123',
      user: { id: 'admin_1', email: 'admin@medusajs.com' }
    }));
    return;
  }

  // Get products
  if (pathname === '/store/products' || pathname === '/admin/products') {
    res.writeHead(200);
    res.end(JSON.stringify({ products: testData.products }));
    return;
  }

  // Get product by ID
  if (pathname.match(/^\/store\/products\/|^\/admin\/products\//)) {
    const id = pathname.split('/').pop();
    const product = testData.products.find(p => p.id === id);
    if (product) {
      res.writeHead(200);
      res.end(JSON.stringify({ product }));
    } else {
      res.writeHead(404);
      res.end(JSON.stringify({ error: 'Not found' }));
    }
    return;
  }

  // Create cart
  if (pathname === '/store/carts' && method === 'POST') {
    const cartId = 'cart_' + Date.now();
    testData.carts[cartId] = { id: cartId, items: [], total: 0 };
    res.writeHead(201);
    res.end(JSON.stringify({ cart: testData.carts[cartId] }));
    return;
  }

  // Get cart
  if (pathname.match(/^\/store\/carts\//)) {
    const cartId = pathname.split('/').pop();
    const cart = testData.carts[cartId];
    if (cart) {
      res.writeHead(200);
      res.end(JSON.stringify({ cart }));
    } else {
      res.writeHead(404);
      res.end(JSON.stringify({ error: 'Cart not found' }));
    }
    return;
  }

  // Add to cart
  if (pathname.match(/^\/store\/carts\/.*\/line-items/) && method === 'POST') {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
      const cartId = pathname.split('/')[3];
      const data = JSON.parse(body);
      const cart = testData.carts[cartId];
      if (cart) {
        const product = testData.products.find(p => p.id === data.variant_id);
        if (product) {
          cart.items.push({
            id: 'item_' + Date.now(),
            product_id: product.id,
            product_title: product.title,
            quantity: data.quantity || 1,
            price: product.price
          });
          cart.total = cart.items.reduce((sum, item) => sum + (item.price * item.quantity), 0);
          res.writeHead(200);
          res.end(JSON.stringify({ cart }));
        } else {
          res.writeHead(404);
          res.end(JSON.stringify({ error: 'Product not found' }));
        }
      } else {
        res.writeHead(404);
        res.end(JSON.stringify({ error: 'Cart not found' }));
      }
    });
    return;
  }

  // Complete cart / Create order
  if (pathname.match(/^\/store\/carts\/.*\/complete/) && method === 'POST') {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
      const cartId = pathname.split('/')[3];
      const cart = testData.carts[cartId];
      if (cart && cart.items.length > 0) {
        const order = {
          id: 'order_' + Date.now(),
          cart_id: cartId,
          items: cart.items,
          total: cart.total,
          status: 'pending',
          created_at: new Date().toISOString(),
          confirmation: {
            number: '#' + Math.random().toString(36).substr(2, 9).toUpperCase(),
            message: 'Thank you for your order!'
          }
        };
        testData.orders.push(order);
        res.writeHead(200);
        res.end(JSON.stringify({ order, success: true }));
      } else {
        res.writeHead(400);
        res.end(JSON.stringify({ error: 'Cart is empty' }));
      }
    });
    return;
  }

  // 404
  res.writeHead(404);
  res.end(JSON.stringify({ error: 'Not found' }));
});

const PORT = 9000;
server.listen(PORT, () => {
  console.log('');
  console.log('╔════════════════════════════════════════════════════════╗');
  console.log('║  🧪 MOCK MEDUSA SERVER (Teste)                        ║');
  console.log('╠════════════════════════════════════════════════════════╣');
  console.log(`║  ✅ Servidor rodando em http://localhost:${PORT}              ║`);
  console.log('║  ✅ 5 produtos de teste carregados                    ║');
  console.log('║  ✅ Carrinho + Checkout funcionando                   ║');
  console.log('║  ✅ Pronto para testar                                ║');
  console.log('╚════════════════════════════════════════════════════════╝');
  console.log('');
  console.log('Endpoints disponíveis:');
  console.log('  GET  /health');
  console.log('  POST /admin/auth/login');
  console.log('  GET  /store/products');
  console.log('  POST /store/carts');
  console.log('  POST /store/carts/{id}/line-items');
  console.log('  POST /store/carts/{id}/complete');
  console.log('');
  console.log('Credenciais de teste:');
  console.log('  Email: admin@medusajs.com');
  console.log('  Password: supersecret');
  console.log('');
});

process.on('SIGINT', () => {
  console.log('\n✅ Servidor encerrado');
  process.exit(0);
});
