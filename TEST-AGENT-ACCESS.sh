#!/bin/bash
# Test script for AI Agent access to MCP Server

HOST="137.131.156.17"
PORT="5556"
BASE_URL="http://$HOST:$PORT"

# API Keys
CLAUDE_KEY="sk-claude-default-key"
OPENAI_KEY="sk-openai-default-key"
GEMINI_KEY="sk-gemini-default-key"

echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║     Testing AI Agent Access to MCP Universal Server           ║"
echo "║     Host: $HOST:$PORT                                        ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""

# Test 1: Basic Connectivity (No Auth)
echo "═══ Test 1: Basic Connectivity (No Auth) ═══"
echo "Endpoint: GET /status"
curl -s "$BASE_URL/status" -w "\nHTTP Status: %{http_code}\n" || echo "❌ FAILED"
echo ""

# Test 2: Health Check (No Auth)
echo "═══ Test 2: Health Check (No Auth) ═══"
echo "Endpoint: GET /health"
curl -s "$BASE_URL/health" -w "\nHTTP Status: %{http_code}\n" || echo "❌ FAILED"
echo ""

# Test 3: List Tools (No Auth)
echo "═══ Test 3: List Tools (No Auth) ═══"
echo "Endpoint: GET /tools"
curl -s "$BASE_URL/tools" -w "\nHTTP Status: %{http_code}\n" || echo "❌ FAILED"
echo ""

# Test 4: Claude - Execute Command
echo "═══ Test 4: Claude Agent - Execute whoami ═══"
echo "Provider: Claude"
echo "Key: $CLAUDE_KEY"
curl -s -X POST "$BASE_URL/exec" \
  -H "X-API-Key: $CLAUDE_KEY" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"whoami","timeout":10}' \
  -w "\nHTTP Status: %{http_code}\n" || echo "❌ FAILED"
echo ""

# Test 5: OpenAI - Execute Command
echo "═══ Test 5: OpenAI GPT - Execute whoami ═══"
echo "Provider: OpenAI"
echo "Key: $OPENAI_KEY"
curl -s -X POST "$BASE_URL/exec" \
  -H "X-API-Key: $OPENAI_KEY" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"whoami","timeout":10}' \
  -w "\nHTTP Status: %{http_code}\n" || echo "❌ FAILED"
echo ""

# Test 6: Gemini - Execute Command
echo "═══ Test 6: Gemini - Execute whoami ═══"
echo "Provider: Gemini"
echo "Key: $GEMINI_KEY"
curl -s -X POST "$BASE_URL/exec" \
  -H "X-API-Key: $GEMINI_KEY" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"whoami","timeout":10}' \
  -w "\nHTTP Status: %{http_code}\n" || echo "❌ FAILED"
echo ""

# Test 7: Git Status
echo "═══ Test 7: Claude - Git Status ═══"
curl -s -X POST "$BASE_URL/git/status" \
  -H "X-API-Key: $CLAUDE_KEY" \
  -H "Content-Type: application/json" \
  -d '{"path":"/home/shopvivaliz/site-shopvivaliz"}' \
  -w "\nHTTP Status: %{http_code}\n" || echo "❌ FAILED"
echo ""

# Test 8: Service Status
echo "═══ Test 8: Service Status Check ═══"
curl -s -X POST "$BASE_URL/service/status" \
  -H "X-API-Key: $CLAUDE_KEY" \
  -H "Content-Type: application/json" \
  -d '{"service":"shopvivaliz-sync"}' \
  -w "\nHTTP Status: %{http_code}\n" || echo "❌ FAILED"
echo ""

echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║                    Tests Complete                             ║"
echo "║  All endpoints should return 200 (success) or 401 (auth fail) ║"
echo "║  200 = Working | 401 = Bad key | 500 = Server error          ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
