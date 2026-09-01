<?php
declare(strict_types=1);

function sv_ar_env_bool(array $env,string $key,bool $default): bool
{
    if (!array_key_exists($key,$env)) return $default;
    $value=strtolower(trim((string)$env[$key]));
    if (in_array($value,['1','true','yes','on'],true)) return true;
    if (in_array($value,['0','false','no','off'],true)) return false;
    return $default;
}

/** @return array{auto_create_safe_t:bool,auto_reply_info:bool,auto_appeal:bool,kill_switch:bool} */
function sv_ar_feature_flags(?array $env=null): array
{
    if ($env===null) {
        $env=[];
        foreach (['AMAZON_RECOVERY_AUTO_CREATE_SAFE_T','AMAZON_RECOVERY_AUTO_REPLY_INFO','AMAZON_RECOVERY_AUTO_APPEAL','AMAZON_RECOVERY_KILL_SWITCH'] as $key) {
            $value=getenv($key); if (is_string($value)) $env[$key]=$value;
        }
    }
    return ['auto_create_safe_t'=>sv_ar_env_bool($env,'AMAZON_RECOVERY_AUTO_CREATE_SAFE_T',false),'auto_reply_info'=>sv_ar_env_bool($env,'AMAZON_RECOVERY_AUTO_REPLY_INFO',false),'auto_appeal'=>sv_ar_env_bool($env,'AMAZON_RECOVERY_AUTO_APPEAL',false),'kill_switch'=>sv_ar_env_bool($env,'AMAZON_RECOVERY_KILL_SWITCH',true)];
}

function sv_ar_action_enabled(string $action,array $flags): bool
{
    if ((bool)($flags['kill_switch'] ?? true)) return false;
    return match(strtoupper($action)) {'OPEN_SAFE_T'=>(bool)($flags['auto_create_safe_t'] ?? false),'RESPOND_INFO_REQUEST'=>(bool)($flags['auto_reply_info'] ?? false),'APPEAL_SAFE_T'=>(bool)($flags['auto_appeal'] ?? false),default=>false};
}
