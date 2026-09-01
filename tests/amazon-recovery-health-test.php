<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/health.php';
$g=sv_ar_health_from_metrics(['unclassified_cases'=>0,'eligible_without_action'=>0,'missed_deadlines'=>0,'unreconciled_credits'=>0,'uncertain_actions'=>0,'dlq_unresolved'=>0,'orphan_events'=>0,'unknown_active_policies'=>0]);ar_t_eq('healthy',$g['status'],'zero gates healthy');
$b=sv_ar_health_from_metrics(['unclassified_cases'=>0,'eligible_without_action'=>2,'missed_deadlines'=>1,'unreconciled_credits'=>0,'uncertain_actions'=>0,'dlq_unresolved'=>0,'orphan_events'=>0,'unknown_active_policies'=>0]);ar_t_eq('critical',$b['status'],'missed deadline critical');ar_t_assert(in_array('missed_deadlines',$b['violations'],true),'deadline reported');
$d=sv_ar_health_from_metrics(['unclassified_cases'=>0,'eligible_without_action'=>0,'missed_deadlines'=>0,'unreconciled_credits'=>1,'uncertain_actions'=>0,'dlq_unresolved'=>0,'orphan_events'=>0,'unknown_active_policies'=>0]);ar_t_eq('degraded',$d['status'],'credit divergence degraded');
ar_t_ok('health gates');
