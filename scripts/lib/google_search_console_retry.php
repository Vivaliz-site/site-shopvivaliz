<?php
declare(strict_types=1);

/**
 * Retry read-only URL Inspection calls when Google returns transient HTTP errors.
 *
 * @param callable():array{status:int,body:mixed,raw:string} $request
 * @param null|callable(int):void $sleep Receives microseconds; injectable for tests.
 * @return array{status:int,body:mixed,raw:string}
 */
function gsc_url_inspection_request_with_retry(
    callable $request,
    int $maxAttempts = 3,
    ?callable $sleep = null
): array {
    if ($maxAttempts < 1) {
        throw new InvalidArgumentException('maxAttempts must be at least 1.');
    }

    $sleep ??= static function (int $microseconds): void {
        usleep($microseconds);
    };

    $response = ['status' => 0, 'body' => null, 'raw' => ''];
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $response = $request();
        $status = (int)($response['status'] ?? 0);
        $transient = $status === 429 || ($status >= 500 && $status <= 599);

        if (!$transient || $attempt === $maxAttempts) {
            return $response;
        }

        $delay = 250000 * (2 ** ($attempt - 1));
        $sleep($delay);
    }

    return $response;
}
