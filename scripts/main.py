#!/usr/bin/env python3
import sys

from import_shopee import main as import_shopee_main
from process_images import main as process_images_main
from upload_images import main as upload_images_main
from generate_shopee_sheet import main as generate_shopee_sheet_main
from send_email import main as send_email_main

if __name__ == '__main__':
    for step in [
        ('import_shopee', import_shopee_main, [sys.argv[1:]]),
        ('process_images', process_images_main, []),
        ('upload_images', upload_images_main, []),
        ('generate_shopee_sheet', generate_shopee_sheet_main, []),
        ('send_email', send_email_main, []),
    ]:
        name, func, args = step
        print(f'=== RUNNING: {name} ===')
        result = func(*args)
        if result != 0:
            print(f'ERROR: step {name} failed with code {result}')
            sys.exit(result)
    sys.exit(0)
