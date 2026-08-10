<?php

declare(strict_types=1);

$useRealQueue = in_array('--real-queue', $argv, true);
if (!$useRealQueue) {
    $tmpQueue = tempnam(sys_get_temp_dir(), 'sv-queue-smoke-');
    if ($tmpQueue === false) {
        fwrite(STDERR, "failed_to_create_temp_queue\n");
        exit(1);
    }
    @unlink($tmpQueue);
    $tmpQueue .= '.sqlite';
    $tmpQueueFile = $tmpQueue . '.json';
    putenv('SHOPVIVALIZ_QUEUE_DSN=sqlite:' . $tmpQueue);
    putenv('SHOPVIVALIZ_QUEUE_FILE=' . $tmpQueueFile);
    $_ENV['SHOPVIVALIZ_QUEUE_DSN'] = 'sqlite:' . $tmpQueue;
    $_ENV['SHOPVIVALIZ_QUEUE_FILE'] = $tmpQueueFile;
}

require_once __DIR__ . '/../core/queue/queue.php';

$sampleBaseImage = __DIR__ . '/../imports/shopee-media-space/images/341440872_KIT4R-SOPR_O_shopee.jpg';
$sampleBaseImage = is_file($sampleBaseImage) ? realpath($sampleBaseImage) : null;

$jobId = sv_queue_enqueue('ai_image_studio.process_item', [
    'product_id' => 1,
    'provider' => 'openai',
    'image_types' => ['white'],
    'model_override' => null,
    'target_channel' => 'site',
    'product' => [
        'name' => 'Produto de validação ShopVivaliz',
        'description' => 'Produto real usado apenas para smoke test do fluxo AI Studio.',
        'image_ref' => $sampleBaseImage,
        'sku' => 'SMOKE-001',
        'olist_id' => 'SMOKE-001',
        'category' => 'teste',
        'brand' => 'ShopVivaliz',
        'model' => 'smoke',
        'color' => 'neutro',
        'size' => 'unico',
        'material' => 'misc',
    ],
    'base_image_path' => $sampleBaseImage,
    'base_image_is_temp' => false,
    'prompts' => [
        'white' => 'Use the supplied real product photo. Keep identity untouched. Pure white background. No text, no props.',
    ],
    'requested_at' => gmdate(DATE_ATOM),
], 25);

echo json_encode([
    'job_id' => $jobId,
    'real_queue' => $useRealQueue,
    'summary_before' => sv_queue_summary(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
