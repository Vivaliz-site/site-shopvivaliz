#!/usr/bin/env bash
set -euo pipefail
script=js/catalog-conversion-v4.js
page=catalogo.php

grep -Fq 'catalog-conversion-v4.js' "$page"
if grep -Fq "document.createElement('nav')" "$script" && grep -Fq "bottom.className='sv-mobile-bottom-nav'" "$script"; then
  echo 'catalog still creates legacy mobile navigation' >&2
  exit 1
fi
if grep -Fq "document.querySelectorAll('.sv-mobile-bottom-nav')" "$script"; then
  echo 'catalog still owns legacy mobile navigation' >&2
  exit 1
fi
echo 'catalog-single-mobile-nav-regression: ok'
