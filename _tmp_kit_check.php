<?php
require __DIR__ . '/config/bootstrap-env.php';
sv_bootstrap_env();
require __DIR__ . '/api/catalog/products.php'; // registers svcat_* helpers; harmless side output ignored via CLI
