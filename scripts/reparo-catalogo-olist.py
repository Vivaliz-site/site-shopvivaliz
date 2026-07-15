#!/usr/bin/env python3
"""
Reparador de Catálogo Olist - Python
Executa reparo direto no banco de dados
"""

import mysql.connector
import json
from datetime import datetime
import os
import csv

# Credenciais
CONFIG = {
        'host': os.getenv('DB_HOST', 'localhost'),
        'user': os.getenv('DB_USER', 'root'),
        'password': os.getenv('DB_PASS', ''),
        'database': os.getenv('DB_NAME', 'shopvivaliz')
}

def conectar():
    """Conecta ao banco"""
    try:
        return mysql.connector.connect(**CONFIG)
    except Exception as e:
        print(f"[ERRO] Conexão: {e}")
        return None

def executar_reparo():
    """Executa o reparo completo"""
    db = conectar()
    if not db:
        return False

    cursor = db.cursor()
    log = []
    start = datetime.now()

    try:
        # 1. CONTAR ANTES
        cursor.execute("SELECT COUNT(*) FROM products")
        before_products = cursor.fetchone()[0]

        cursor.execute("SELECT COUNT(*) FROM olist_products")
        before_olist = cursor.fetchone()[0]

        cursor.execute("SELECT COUNT(*) FROM olist_product_images")
        before_images = cursor.fetchone()[0]

        print(f"[ANTES] products={before_products}, olist_products={before_olist}, images={before_images}")
        log.append(f"[ANTES] products={before_products}, olist_products={before_olist}, images={before_images}")

        # 2. IMPORTAR PRODUTOS
        insert_sql = """
        INSERT INTO products (sku, product_id, name, price, description, category, stock, image_url, active, created_at, updated_at)
        SELECT
            op.sku,
            op.olist_product_id,
            op.name,
            op.price,
            op.description,
            op.category,
            op.stock,
            op.primary_image_url,
            1,
            NOW(),
            NOW()
        FROM olist_products op
        LEFT JOIN products p ON p.sku = op.sku
        WHERE p.id IS NULL
        AND op.sku IS NOT NULL
        AND op.sku != ''
        ON DUPLICATE KEY UPDATE
            name=VALUES(name),
            price=VALUES(price),
            description=VALUES(description),
            stock=VALUES(stock),
            updated_at=NOW()
        """

        cursor.execute(insert_sql)
        inserted = cursor.rowcount
        db.commit()
        print(f"[OK] Inseridos {inserted} produtos")
        log.append(f"[OK] Inseridos {inserted} produtos")

        # 3. VINCULAR IMAGENS POR SKU
        link_sql = """
        UPDATE olist_product_images img
        JOIN olist_products op ON img.sku = op.sku
        JOIN products p ON p.sku = op.sku
        SET img.product_local_id = p.id
        WHERE (img.product_local_id IS NULL OR img.product_local_id = 0)
        AND img.sku IS NOT NULL
        AND img.sku != ''
        """

        cursor.execute(link_sql)
        linked = cursor.rowcount
        db.commit()
        print(f"[OK] Vinculadas {linked} imagens por SKU")
        log.append(f"[OK] Vinculadas {linked} imagens por SKU")

        # 4. ATUALIZAR IMAGENS PRINCIPAIS
        update_primary_sql = """
        UPDATE olist_products op
        SET
            op.primary_image_url = (
                SELECT url FROM olist_product_images
                WHERE product_local_id = (SELECT id FROM products WHERE sku = op.sku LIMIT 1)
                ORDER BY position LIMIT 1
            ),
            op.images_count = (
                SELECT COUNT(*) FROM olist_product_images
                WHERE product_local_id = (SELECT id FROM products WHERE sku = op.sku LIMIT 1)
            ),
            op.image_sync_status = 'linked',
            op.last_image_sync_at = NOW()
        WHERE op.sku IN (SELECT DISTINCT sku FROM products)
        """

        cursor.execute(update_primary_sql)
        updated_primary = cursor.rowcount
        db.commit()
        print(f"[OK] Atualizadas {updated_primary} imagens principais")
        log.append(f"[OK] Atualizadas {updated_primary} imagens principais")

        # 5. COPIAR PARA products.image_url
        copy_sql = """
        UPDATE products p
        SET p.image_url = (
            SELECT op.primary_image_url FROM olist_products op WHERE op.sku = p.sku LIMIT 1
        )
        WHERE p.sku IS NOT NULL
        AND p.sku != ''
        AND (p.image_url IS NULL OR p.image_url = '')
        """

        cursor.execute(copy_sql)
        copy_images = cursor.rowcount
        db.commit()
        print(f"[OK] Copiadas {copy_images} imagens para products.image_url")
        log.append(f"[OK] Copiadas {copy_images} imagens para products.image_url")

        # 6. ATIVAR PRODUTOS COM IMAGEM
        activate_sql = """
        UPDATE products p
        SET p.active = 1
        WHERE p.sku IN (
            SELECT DISTINCT sku FROM olist_products WHERE primary_image_url IS NOT NULL
        )
        """

        cursor.execute(activate_sql)
        db.commit()
        print(f"[OK] Ativados produtos com imagem")
        log.append(f"[OK] Ativados produtos com imagem")

        # 7. CONTAR DEPOIS
        cursor.execute("SELECT COUNT(*) FROM products")
        after_products = cursor.fetchone()[0]

        cursor.execute("SELECT COUNT(*) FROM olist_products")
        after_olist = cursor.fetchone()[0]

        cursor.execute("SELECT COUNT(*) FROM olist_product_images WHERE product_local_id > 0")
        after_images = cursor.fetchone()[0]

        print(f"[DEPOIS] products={after_products}, olist_products={after_olist}, images_linked={after_images}")
        log.append(f"[DEPOIS] products={after_products}, olist_products={after_olist}, images_linked={after_images}")

        # 8. CRIAR CSV DE AUDITORIA
        audit_file = 'storage/reports/catalogo_olist_reparo.csv'
        os.makedirs(os.path.dirname(audit_file), exist_ok=True)

        audit_sql = """
        SELECT
            op.sku,
            op.olist_product_id,
            COALESCE(p.id, 0) as product_local_id,
            op.name as product_name,
            IF(p.id IS NOT NULL, 'SIM', 'NÃO') as exists_in_products,
            op.images_count,
            op.primary_image_url,
            IF(op.primary_image_url IS NOT NULL AND op.primary_image_url != '', 'COM_IMAGEM', 'SEM_IMAGEM') as status
        FROM olist_products op
        LEFT JOIN products p ON p.sku = op.sku
        ORDER BY op.sku
        """

        cursor.execute(audit_sql)
        with open(audit_file, 'w', newline='', encoding='utf-8') as f:
            writer = csv.writer(f)
            writer.writerow(['sku', 'olist_product_id', 'product_local_id', 'product_name', 'exists_in_products', 'images_count', 'primary_image_url', 'status'])
            for row in cursor.fetchall():
                writer.writerow(row)

        print(f"[OK] CSV criado em {audit_file}")
        log.append(f"[OK] CSV criado em {audit_file}")

        # RESULTADO FINAL
        duration = (datetime.now() - start).total_seconds()
        result = {
            'ok': True,
            'produtos_antes': before_products,
            'produtos_depois': after_products,
            'produtos_esperados': 196,
            'images_linked': after_images,
            'primary_images_updated': updated_primary,
            'csv_auditoria': audit_file,
            'duracao_seg': round(duration, 2),
            'status': 'COMPLETO' if after_products >= 196 else 'INCOMPLETO',
            'log': log
        }

        print(json.dumps(result, indent=2, ensure_ascii=False))

        # Registrar log
        log_file = f"logs/reparacao-olist-{datetime.now().strftime('%Y-%m-%d-%H-%M-%S')}.json"
        os.makedirs(os.path.dirname(log_file), exist_ok=True)
        with open(log_file, 'w', encoding='utf-8') as f:
            json.dump(result, f, indent=2, ensure_ascii=False)

        print(f"[OK] Log salvo em {log_file}")

        return True

    except Exception as e:
        print(f"[ERRO] {e}")
        import traceback
        traceback.print_exc()
        return False

    finally:
        cursor.close()
        db.close()

if __name__ == '__main__':
    print("=" * 60)
    print("REPARADOR DE CATÁLOGO OLIST")
    print("=" * 60)
    executar_reparo()
    print("=" * 60)
