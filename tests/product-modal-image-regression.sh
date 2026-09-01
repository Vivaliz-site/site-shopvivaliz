#!/usr/bin/env bash
set -Eeuo pipefail
script=js/product-conversion-v5.js

if grep -Fq '<img alt="">' "$script"; then
  echo 'product modal injects an empty image before the modal is opened' >&2
  exit 1
fi
grep -Fq "var modalImage = document.createElement('img');" "$script"
grep -Fq "modalImage.src = image.src;" "$script"
grep -Fq "modal.appendChild(modalImage);" "$script"
echo 'product-modal-image-regression: ok'
