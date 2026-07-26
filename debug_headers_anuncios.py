#!/usr/bin/env python3
"""
Debug: listar headers reais dos arquivos anuncios_*.xls
"""
import os

try:
    import xlrd
except:
    os.system("pip install xlrd -q")
    import xlrd

from pathlib import Path

downloads_path = Path(r"C:\Users\user\Downloads")
anuncios_files = sorted(downloads_path.glob("anuncios_2026-07-26-12-*.xls"))

print("\n" + "="*100)
print("HEADERS DOS ARQUIVOS ANUNCIOS")
print("="*100)

for file_path in anuncios_files[:2]:  # Apenas os 2 primeiros
    print(f"\n[ARQUIVO: {file_path.name}]")
    print("="*100)

    try:
        workbook = xlrd.open_workbook(str(file_path))
        worksheet = workbook.sheet_by_index(0)

        print(f"Total de colunas: {worksheet.ncols}")
        print(f"Total de linhas: {worksheet.nrows}")

        print("\nHEADERS (Linha 1):")
        print("-"*100)

        for col in range(worksheet.ncols):
            header = worksheet.cell_value(0, col)
            print(f"  Col {col+1:2d}: {header}")

        print("\nPRIMEIRA LINHA DE DADOS:")
        print("-"*100)

        if worksheet.nrows > 1:
            for col in range(min(10, worksheet.ncols)):
                header = worksheet.cell_value(0, col)
                value = worksheet.cell_value(1, col)
                print(f"  {header:20s}: {value}")

    except Exception as e:
        print(f"  Erro: {str(e)[:100]}")

print("\n" + "="*100)
