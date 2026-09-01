<?php
declare(strict_types=1);

/** @return array<string,mixed> */
function sv_ar_policy_for_case(array $case, DateTimeImmutable $at): array
{
    $reason = strtoupper(trim((string)($case['claim_reason'] ?? '')));
    $program = strtoupper(trim((string)($case['amazon_program'] ?? '')));
    $orderDateRaw = trim((string)($case['order_date'] ?? ''));
    if ($reason !== 'RETURN_NOT_RECEIVED') {
        return ['policy_key'=>'UNKNOWN','version'=>1,'wait_days'=>0,'basis'=>'refund_at','requires_human_review'=>true,'effective_from'=>null,'source'=>'unmapped'];
    }
    $cutover = new DateTimeImmutable('2026-04-21T00:00:00-03:00');
    $orderDate = $orderDateRaw !== '' ? new DateTimeImmutable($orderDateRaw) : null;
    $isLongWindow = in_array($program, ['DELIVERY_BY_AMAZON','FBA_ONSITE'], true) && $orderDate instanceof DateTimeImmutable && $orderDate >= $cutover;
    return [
        'policy_key'=>$isLongWindow ? 'BR_RETURN_NOT_RECEIVED_D60_20260421' : 'BR_RETURN_NOT_RECEIVED_D45',
        'version'=>1,
        'wait_days'=>$isLongWindow ? 60 : 45,
        'basis'=>'refund_at',
        'requires_human_review'=>false,
        'effective_from'=>$isLongWindow ? '2026-04-21' : '2026-01-01',
        'source'=>$isLongWindow ? 'amazon-br-policy-onsite-dba-2026-04-21' : 'amazon-br-returning-to-seller-d45',
    ];
}

function sv_ar_eligibility_at(array $case, array $policy): DateTimeImmutable
{
    $basis = (string)($policy['basis'] ?? 'refund_at');
    $raw = trim((string)($case[$basis] ?? ''));
    if ($raw === '') throw new InvalidArgumentException("Missing policy basis timestamp: {$basis}");
    return (new DateTimeImmutable($raw))->modify('+' . max(0, (int)($policy['wait_days'] ?? 0)) . ' days');
}
