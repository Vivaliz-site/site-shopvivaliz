#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script para testar conexao com API do TikTok Shop
Uso: python test_tiktok_api.py
"""

import requests
import os
import json

BASE_URL = "https://open-api.tiktokglobalshop.com"

def get_headers():
    """Retorna headers com autenticacao"""
    access_token = os.getenv("TIKTOK_ACCESS_TOKEN")

    if not access_token:
        print("[ERRO] TIKTOK_ACCESS_TOKEN nao encontrado")
        return None

    return {
        "Authorization": f"Bearer {access_token}",
        "Content-Type": "application/json"
    }

def test_shop_info():
    """Testa obter informacoes da loja"""
    print("\n[*] Testando: GET /shop/1.0/shop/get_shop_info")

    headers = get_headers()
    if not headers:
        return False

    try:
        url = f"{BASE_URL}/shop/1.0/shop/get_shop_info"
        response = requests.get(url, headers=headers)

        print(f"    Status: {response.status_code}")

        if response.status_code == 200:
            data = response.json()
            if data.get("data"):
                print(f"    [OK] Shop info obtido")
                print(f"    Shop ID: {data['data'].get('shop_id')}")
                print(f"    Shop Name: {data['data'].get('shop_name')}")
                return True
            else:
                print(f"    [ERRO] {data.get('message')}")
                return False
        else:
            print(f"    [ERRO] Status {response.status_code}")
            print(f"    {response.text}")
            return False

    except Exception as e:
        print(f"    [ERRO] {e}")
        return False

def test_base_info():
    """Testa obter informacoes basicas"""
    print("\n[*] Testando: GET /shop/1.0/shop/get_base_info")

    headers = get_headers()
    if not headers:
        return False

    try:
        url = f"{BASE_URL}/shop/1.0/shop/get_base_info"
        response = requests.get(url, headers=headers)

        print(f"    Status: {response.status_code}")

        if response.status_code == 200:
            data = response.json()
            if data.get("data"):
                print(f"    [OK] Base info obtido")
                return True
            else:
                print(f"    [ERRO] {data.get('message')}")
                return False
        else:
            print(f"    [ERRO] Status {response.status_code}")
            return False

    except Exception as e:
        print(f"    [ERRO] {e}")
        return False

def test_search_products():
    """Testa buscar produtos"""
    print("\n[*] Testando: GET /product/202309/products/search")

    headers = get_headers()
    if not headers:
        return False

    shop_id = os.getenv("TIKTOK_SHOP_ID")

    if not shop_id:
        print(f"    [SKIP] TIKTOK_SHOP_ID nao configurado")
        return None

    try:
        url = f"{BASE_URL}/product/202309/products/search?shop_id={shop_id}&page_size=10"
        response = requests.get(url, headers=headers)

        print(f"    Status: {response.status_code}")

        if response.status_code == 200:
            data = response.json()
            if data.get("data"):
                print(f"    [OK] Produtos obtidos")
                print(f"    Total: {len(data['data'].get('products', []))}")
                return True
            else:
                print(f"    [ERRO] {data.get('message')}")
                return False
        else:
            print(f"    [ERRO] Status {response.status_code}")
            return False

    except Exception as e:
        print(f"    [ERRO] {e}")
        return False

def main():
    print("=" * 60)
    print("TESTE DE CONEXAO COM API DO TIKTOK SHOP")
    print("=" * 60)

    access_token = os.getenv("TIKTOK_ACCESS_TOKEN")

    print(f"\nConfiguracao:")
    print(f"  Access Token: {access_token[:20] if access_token else 'NAO ENCONTRADO'}...")
    print(f"  Base URL: {BASE_URL}\n")

    if not access_token:
        print("[ERRO] TIKTOK_ACCESS_TOKEN nao esta configurado")
        print("Configure: export TIKTOK_ACCESS_TOKEN='seu_token'")
        return

    tests = [
        test_shop_info,
        test_base_info,
        test_search_products
    ]

    passed = 0
    failed = 0
    skipped = 0

    for test_func in tests:
        result = test_func()
        if result is True:
            passed += 1
        elif result is False:
            failed += 1
        else:
            skipped += 1

    print("\n" + "=" * 60)
    print(f"RESULTADO: {passed} OK | {failed} ERRO | {skipped} SKIP")
    print("=" * 60)

    if failed == 0:
        print("\n[SUCESSO] API do TikTok Shop esta funcionando corretamente!")
    else:
        print("\n[AVISO] Alguns testes falharam - verifique os erros acima")

if __name__ == "__main__":
    main()
