<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-identity-smoke-resolver.php';

function expect_candidate(?array $actual, ?array $expected, string $case): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$case}\n");
        exit(1);
    }
}

$canonical = ['id' => 7, 'is_admin' => 1];
$adminA = ['id' => 8, 'is_admin' => 1];
$adminB = ['id' => 9, 'is_admin' => 1];
$nonAdmin = ['id' => 10, 'is_admin' => 0];

expect_candidate(sv_choose_admin_smoke_identity($canonical, $adminA, [$adminA, $adminB]), $canonical, 'canonical identity has priority');
expect_candidate(sv_choose_admin_smoke_identity(null, $adminA, [$adminA, $adminB]), $adminA, 'existing domain admin resolves among multiple accounts');
expect_candidate(sv_choose_admin_smoke_identity(null, null, [$nonAdmin]), $nonAdmin, 'unique domain account fallback');
expect_candidate(sv_choose_admin_smoke_identity(null, null, [$nonAdmin, ['id' => 11, 'is_admin' => 0]]), null, 'ambiguous non-admin identities are rejected');
expect_candidate(sv_choose_admin_smoke_identity(null, ['id' => 12, 'is_admin' => 0], [$nonAdmin, $adminB]), null, 'non-admin candidate is not accepted');

echo "ADMIN_IDENTITY_SMOKE_RESOLVER_TEST=PASS cases=5\n";
