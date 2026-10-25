<?php

declare(strict_types=1);

require_once __DIR__ . '/broker-client.php';
require_once __DIR__ . '/signer-client.php';

/** Coordinates an internal signer and broker without exposing capability material. */
final class SvMarinaMaintenanceAuthorizationService
{
    public function __construct(
        private SvMarinaMaintenanceBrokerClient $broker,
        private SvMarinaMaintenanceSignerClient $signer,
    ) {
    }

    public function readiness(): array
    {
        $broker = $this->broker->status();
        $signer = $this->signer->status();
        $signerReady = ($signer['state'] ?? '') === 'READY';
        $state = (($broker['state'] ?? '') === 'NAO_APTO' || !$signerReady) ? 'NAO_APTO' : (string)($broker['state'] ?? 'UNKNOWN');
        $usable = in_array($state, ['READY_FOR_APPROVAL', 'APPROVAL_REQUIRED', 'SUCCEEDED_RECONCILED'], true);
        return [
            'ok' => $usable,
            'state' => $state,
            'code' => $state === 'NAO_APTO' ? (($broker['code'] ?? '') ?: ($signer['code'] ?? 'signer_not_ready')) : (string)($broker['code'] ?? ''),
            'broker' => sv_marina_audit_projection($broker),
            'signer' => sv_marina_audit_projection($signer),
        ];
    }

    /** @param array<string, mixed> $context */
    public function preview(array $context): array
    {
        $preflight = $this->broker->preflight($context);
        $preflight['signer'] = sv_marina_audit_projection($this->signer->status());
        return $preflight;
    }

    /** @param array<string, mixed> $context */
    public function authorize(array $context): array
    {
        $signer = $this->signer->status();
        if (($signer['state'] ?? '') !== 'READY') {
            return ['ok' => false, 'state' => (string)($signer['state'] ?? 'NAO_APTO'), 'code' => (string)($signer['code'] ?? 'signer_not_ready')];
        }
        $preflight = $this->broker->preflight($context);
        if (($preflight['state'] ?? '') !== 'APPROVAL_REQUIRED') {
            return $preflight;
        }
        $issued = $this->signer->issue($context, $preflight);
        if (($issued['state'] ?? '') !== 'TICKET_ISSUED' || !is_array($issued['ticket'] ?? null)) {
            return ['ok' => false, 'state' => (string)($issued['state'] ?? 'REFUSED'), 'code' => (string)($issued['code'] ?? 'signer_refused')];
        }
        $result = $this->broker->authorize($issued['ticket'], (string)$issued['signature_b64'], $context, $preflight);
        unset($issued);
        return $result;
    }
}
