<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/decision-engine.php';
$base=['case_state'=>'WAITING_ELIGIBILITY','claim_reason'=>'RETURN_NOT_RECEIVED','physical_received'=>false,'is_reimbursed'=>false,'safe_t_id'=>null,'eligibility_at'=>'2026-08-24T12:00:00-03:00','requires_human_review'=>false,'safe_t_status'=>null,'info_required'=>false];
$now=new DateTimeImmutable('2026-09-01T12:00:00-03:00');ar_t_eq('OPEN_SAFE_T',sv_ar_decide_case($base,$now,[])['action'],'eligible case opens SAFE-T');
$c=$base;$c['physical_received']=true;ar_t_eq('RECONCILE_RETURN',sv_ar_decide_case($c,$now,[])['action'],'physical receipt blocks not-received claim');
$c=$base;$c['is_reimbursed']=true;ar_t_eq('CLOSE_REIMBURSED',sv_ar_decide_case($c,$now,[])['action'],'Amazon reimbursement blocks claim');
$c=$base;$c['safe_t_id']='11111-22222-3333333';$c['safe_t_status']='DENIED';ar_t_eq('APPEAL_SAFE_T',sv_ar_decide_case($c,$now,[])['action'],'denied SAFE-T routes to appeal');
$c['safe_t_status']='OPEN';$c['info_required']=true;ar_t_eq('RESPOND_INFO_REQUEST',sv_ar_decide_case($c,$now,[])['action'],'info request has priority');
$c=$base;$c['requires_human_review']=true;ar_t_eq('REVIEW_REQUIRED',sv_ar_decide_case($c,$now,[])['action'],'uncertain policy blocks auto-submit');
ar_t_eq('REVIEW_REQUIRED',sv_ar_decide_case($base,$now,['kill_switch'=>true])['action'],'kill switch blocks external action');
ar_t_ok('decision engine');
