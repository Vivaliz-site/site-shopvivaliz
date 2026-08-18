<?php
declare(strict_types=1);

/**
 * Enables an explicitly allow-listed Google Cloud API through Service Usage.
 *
 * This is intended for infrastructure bootstrap/self-healing, not arbitrary
 * project mutation. Credentials are read from the existing OAuth environment
 * and no token/credential is printed.
 *
 * Usage:
 *   php scripts/google-cloud-service-enable.php searchconsole.googleapis.com
 */

$root = dirname(__DIR__);
if (is_file($root . '/config/constants.php')) {
    require_once $root . '/config/constants.php';
}
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
} else {
    require_once $root . '/src/Google/OAuthTokenProvider.php';
    require_once $root . '/src/Google/GoogleApiClient.php';
}

use ShopVivaliz\Google\GoogleApiClient;
use ShopVivaliz\Google\OAuthTokenProvider;

const SHOPVIVALIZ_ALLOWED_GOOGLE_SERVICES = [
    'searchconsole.googleapis.com',
    'analyticsdata.googleapis.com',
    'tagmanager.googleapis.com',
    'merchantapi.googleapis.com',
    'indexing.googleapis.com',
];

function gse_error(array $response): string
{
    $body = $response['body'] ?? null;
    if (is_array($body)) {
        $message = $body['error']['message'] ?? $body['error_description'] ?? $body['error'] ?? '';
        if (is_scalar($message) && trim((string)$message) !== '') {
            return trim((string)$message);
        }
    }
    return 'HTTP ' . (int)($response['status'] ?? 0);
}

function gse_project_number(): string
{
    $explicit = trim((string)(getenv('GOOGLE_CLOUD_PROJECT_NUMBER') ?: ''));
    if ($explicit !== '') {
        if (!preg_match('/^\d+$/', $explicit)) {
            throw new RuntimeException('GOOGLE_CLOUD_PROJECT_NUMBER must contain only digits.');
        }
        return $explicit;
    }

    $clientId = trim((string)(getenv('GOOGLE_OAUTH_CLIENT_ID') ?: ''));
    if (preg_match('/^(\d+)-/', $clientId, $matches)) {
        return $matches[1];
    }

    throw new RuntimeException(
        'Could not determine Google Cloud project number. Set GOOGLE_CLOUD_PROJECT_NUMBER.'
    );
}

try {
    $service = trim((string)($argv[1] ?? ''));
    if (!in_array($service, SHOPVIVALIZ_ALLOWED_GOOGLE_SERVICES, true)) {
        throw new RuntimeException(
            'Service is not allow-listed. Allowed: ' . implode(', ', SHOPVIVALIZ_ALLOWED_GOOGLE_SERVICES) . '.'
        );
    }

    $projectNumber = gse_project_number();
    $api = new GoogleApiClient(OAuthTokenProvider::fromEnvironment());
    $resource = 'projects/' . rawurlencode($projectNumber) . '/services/' . rawurlencode($service);

    // Read state first so normal audit runs stay read-only after bootstrap.
    $stateResponse = $api->request(
        'GET',
        'https://serviceusage.googleapis.com/v1/' . $resource
    );

    if (
        $stateResponse['status'] >= 200
        && $stateResponse['status'] < 300
        && is_array($stateResponse['body'])
        && (string)($stateResponse['body']['state'] ?? '') === 'ENABLED'
    ) {
        fwrite(STDOUT, json_encode([
            'service' => $service,
            'projectNumber' => $projectNumber,
            'state' => 'ENABLED',
            'changed' => false,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    // A 403 from services.get can mean the service is disabled OR the caller
    // lacks Service Usage permissions. Attempt enable once; the resulting error
    // is the authoritative diagnostic and is sanitized by GoogleApiClient.
    $enableResponse = $api->request(
        'POST',
        'https://serviceusage.googleapis.com/v1/' . $resource . ':enable',
        []
    );
    if ($enableResponse['status'] < 200 || $enableResponse['status'] >= 300 || !is_array($enableResponse['body'])) {
        throw new RuntimeException(
            'Could not enable ' . $service . ': ' . gse_error($enableResponse)
        );
    }

    $operationName = trim((string)($enableResponse['body']['name'] ?? ''));
    if ($operationName === '') {
        throw new RuntimeException('Service Usage enable returned no operation name.');
    }

    $deadline = time() + 90;
    $operation = null;
    do {
        usleep(1000000);
        $operationResponse = $api->request(
            'GET',
            'https://serviceusage.googleapis.com/v1/' . ltrim($operationName, '/')
        );
        if ($operationResponse['status'] < 200 || $operationResponse['status'] >= 300 || !is_array($operationResponse['body'])) {
            throw new RuntimeException('Could not read Service Usage operation: ' . gse_error($operationResponse));
        }
        $operation = $operationResponse['body'];
        if (($operation['done'] ?? false) === true) {
            break;
        }
    } while (time() < $deadline);

    if (!is_array($operation) || ($operation['done'] ?? false) !== true) {
        throw new RuntimeException('Timed out waiting for Google Cloud service enable operation.');
    }
    if (isset($operation['error'])) {
        $message = is_array($operation['error'])
            ? trim((string)($operation['error']['message'] ?? 'unknown operation error'))
            : 'unknown operation error';
        throw new RuntimeException('Google Cloud service enable operation failed: ' . $message);
    }

    $verifyResponse = $api->request(
        'GET',
        'https://serviceusage.googleapis.com/v1/' . $resource
    );
    $state = is_array($verifyResponse['body'] ?? null)
        ? (string)($verifyResponse['body']['state'] ?? '')
        : '';
    if ($verifyResponse['status'] < 200 || $verifyResponse['status'] >= 300 || $state !== 'ENABLED') {
        throw new RuntimeException('Service enable completed but verification did not report ENABLED.');
    }

    fwrite(STDOUT, json_encode([
        'service' => $service,
        'projectNumber' => $projectNumber,
        'state' => 'ENABLED',
        'changed' => true,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[google-cloud-service-enable] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
