<?php

declare(strict_types=1);

require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/DenialAnalyzer.php';
require_once __DIR__ . '/SafeTStatus.php';

final class SvAmazonSafeTDecisionEngine
{
    public function __construct(private ?SvAmazonDenialAnalyzer $denialAnalyzer = null)
    {
        $this->denialAnalyzer ??= new SvAmazonDenialAnalyzer();
    }

    /** @return array<string,mixed> */
    public function nextAction(array $case, array $timeline, array $policy): array
    {
        $caseId = (int)($case['id'] ?? 0);
        $orderId = trim((string)($case['amazon_order_id'] ?? ''));
        $safeTId = trim((string)($case['safe_t_id'] ?? ''));
        $state = trim((string)($case['state'] ?? ''));

        if ($this->hasRecoveredCredit($case)) {
            return $this->decision('WAIT', 'ALREADY_REIMBURSED', $caseId);
        }
        if ((string)($case['physical_status'] ?? '') === SvAmazonReturnPhysicalStatuses::RECEIVED_OK) {
            return $this->decision('WAIT', 'PHYSICAL_RETURN_RECEIVED', $caseId);
        }
        $initiator = (string)($case['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN);
        if (!SvAmazonRefundInitiators::isValid($initiator) || $initiator === SvAmazonRefundInitiators::UNKNOWN) {
            return $this->decision('BLOCKED_REVIEW', 'REFUND_INITIATOR_UNKNOWN', $caseId);
        }
        if (trim((string)($case['seller_debit_at'] ?? '')) === '') {
            return $this->decision('WAIT', 'SELLER_DEBIT_NOT_CONFIRMED', $caseId);
        }
        if (($policy['state'] ?? null) === SvAmazonReturnStates::POLICY_REVIEW_REQUIRED) {
            return $this->decision('BLOCKED_REVIEW', 'POLICY_REVIEW_REQUIRED', $caseId);
        }
        if (($policy['eligible'] ?? false) !== true) {
            return $this->decision('WAIT', 'NOT_YET_ELIGIBLE', $caseId);
        }

        if ($safeTId === '') {
            $policyId = (string)($policy['policy_version_id'] ?? 'unknown');
            $eligibilityAt = (string)($policy['eligibility_at'] ?? 'unknown');
            return [
                'action' => 'SAFE_T_SUBMIT',
                'reason' => 'FIRST_ELIGIBLE_ATTEMPT',
                'idempotency_key' => hash('sha256', 'safe-t-submit|' . $caseId . '|' . $orderId . '|' . $policyId . '|' . $eligibilityAt),
            ];
        }

        if ($state === SvAmazonReturnStates::SAFE_T_INFO_REQUESTED) {
            return [
                'action' => 'SAFE_T_APPEAL',
                'reason' => 'SAFE_T_INFORMATION_RESPONSE_REQUIRED',
                'idempotency_key' => hash('sha256', 'safe-t-info-response|' . $safeTId . '|' . (string)($case['info_request_fingerprint'] ?? 'current')),
            ];
        }

        if (in_array($state, [SvAmazonReturnStates::SAFE_T_DENIED, SvAmazonReturnStates::APPEAL_REQUIRED, SvAmazonReturnStates::APPEAL_DENIED_FINAL], true)) {
            $denialContext = SvAmazonSafeTStatus::denialContext($timeline);
            $latestText = trim((string)($case['latest_denial_text'] ?? $denialContext['latest_denial_text']));
            if ($latestText === '') {
                return $this->decision('BLOCKED_REVIEW', 'DENIAL_TEXT_MISSING', $caseId);
            }
            $analysisCase = $case;
            if (trim((string)($analysisCase['previous_denial_fingerprint'] ?? '')) === '' && $denialContext['previous_denial_fingerprint'] !== '') {
                $analysisCase['previous_denial_fingerprint'] = $denialContext['previous_denial_fingerprint'];
            }
            $analysis = $this->denialAnalyzer->analyze($analysisCase, $latestText);
            $fingerprint = $analysis['fingerprint'];
            if ($analysis['repeated'] && $analysis['non_substantive']) {
                if ($this->hasActiveSupportCase($case)) {
                    if (($case['new_support_fact'] ?? false) === true) {
                        return [
                            'action' => 'SELLER_SUPPORT_UPDATE',
                            'reason' => 'REPEATED_AUTOMATED_DENIAL_NEW_FACT',
                            'support_case_id' => (string)$case['support_case_id'],
                            'denial_fingerprint' => $fingerprint,
                            'idempotency_key' => hash('sha256', 'support-update|' . (string)$case['support_case_id'] . '|' . $fingerprint . '|' . (string)($case['support_fact_hash'] ?? 'new-fact')),
                        ];
                    }
                    return [
                        'action' => 'WAIT',
                        'reason' => 'SUPPORT_ESCALATION_ALREADY_ACTIVE',
                        'support_case_id' => (string)$case['support_case_id'],
                        'denial_fingerprint' => $fingerprint,
                    ];
                }
                $round = max(1, (int)($case['support_escalation_round'] ?? 1));
                return [
                    'action' => 'SELLER_SUPPORT_OPEN',
                    'reason' => 'REPEATED_AUTOMATED_DENIAL_WITHOUT_SUBSTANTIVE_REVIEW',
                    'denial_fingerprint' => $fingerprint,
                    'repeated_denial_count' => max(1, (int)($case['repeated_denial_count'] ?? 0) + 1),
                    'idempotency_key' => hash('sha256', 'support-open|' . $safeTId . '|' . $fingerprint . '|' . $round),
                ];
            }

            return [
                'action' => 'SAFE_T_APPEAL',
                'reason' => 'SUBSTANTIVE_DENIAL_REQUIRES_ADAPTED_RESPONSE',
                'denial_fingerprint' => $fingerprint,
                'idempotency_key' => hash('sha256', 'safe-t-appeal|' . $safeTId . '|' . $fingerprint),
            ];
        }

        return $this->decision('WAIT', 'SAFE_T_ALREADY_EXISTS', $caseId);
    }

    /** @return array{action:string,reason:string,case_id:int} */
    private function decision(string $action, string $reason, int $caseId): array
    {
        return ['action' => $action, 'reason' => $reason, 'case_id' => $caseId];
    }

    private function hasRecoveredCredit(array $case): bool
    {
        $expected = (float)($case['expected_reimbursement_amount'] ?? 0);
        $credited = (float)($case['reconciled_credit_amount'] ?? 0);
        return $expected > 0.0 && $credited + 0.00001 >= $expected;
    }

    private function hasActiveSupportCase(array $case): bool
    {
        $id = trim((string)($case['support_case_id'] ?? ''));
        if ($id === '') return false;
        $status = strtoupper(trim((string)($case['support_case_status'] ?? 'OPEN')));
        return !in_array($status, ['CLOSED', 'RESOLVED', 'CANCELLED'], true);
    }
}
