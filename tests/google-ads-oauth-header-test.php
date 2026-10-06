<?php
declare(strict_types=1);
$src=(string)file_get_contents(dirname(__DIR__) . '/includes/google-ads-order-conversion.php');
$raw=token_get_all($src);
$tokens=[];
foreach($raw as $token){
    if(is_array($token) && in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) continue;
    $tokens[]=$token;
}
$found=false;
for($i=0;$i<count($tokens)-2;$i++){
    $a=$tokens[$i]; $b=$tokens[$i+1]; $c=$tokens[$i+2];
    if(is_array($a) && $a[0]===T_CONSTANT_ENCAPSED_STRING && str_contains($a[1], 'Authorization: Bearer ')
        && $b==='.' && is_array($c) && $c[0]===T_VARIABLE && $c[1]==='$accessToken'){
        $found=true; break;
    }
}
if(!$found){fwrite(STDERR,"FAIL: OAuth bearer header must concatenate access token.\n"); exit(1);}
echo "PASS: OAuth bearer header is constructed from runtime token.\n";
