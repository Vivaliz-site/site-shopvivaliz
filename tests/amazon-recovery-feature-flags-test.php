<?php
declare(strict_types=1);
require __DIR__.'/amazon-recovery-testlib.php';
require __DIR__.'/../includes/amazon-recovery/feature-flags.php';
$f=sv_ar_feature_flags([]);ar_t_eq(false,$f['auto_create_safe_t'],'auto claim default off');ar_t_eq(false,$f['auto_reply_info'],'auto reply default off');ar_t_eq(false,$f['auto_appeal'],'auto appeal default off');ar_t_eq(true,$f['kill_switch'],'kill switch default on');
$f2=sv_ar_feature_flags(['AMAZON_RECOVERY_KILL_SWITCH'=>'0','AMAZON_RECOVERY_AUTO_CREATE_SAFE_T'=>'1']);ar_t_eq(false,$f2['kill_switch'],'explicit zero releases kill switch');ar_t_eq(true,$f2['auto_create_safe_t'],'explicit one enables claim flag');ar_t_eq(false,sv_ar_action_enabled('APPEAL_SAFE_T',$f2),'appeal still disabled');ar_t_eq(true,sv_ar_action_enabled('OPEN_SAFE_T',$f2),'claim action enabled');
ar_t_ok('feature flags');
