<?php
declare(strict_types=1);
require_once __DIR__.'/OlistAdsIndex.php';

/** Admin-only, CSRF-protected ingestion of Olist exports. No marketplace writes. */
final class SvAmazonMarginOlistAdsImport
{
    public static function process(array $upload): array
    {
        $names=$upload['name']??null;
        $paths=$upload['tmp_name']??null;
        $sizes=$upload['size']??null;
        $errors=$upload['error']??null;
        if (!is_array($names) || !is_array($paths) || !is_array($sizes)
            || !is_array($errors) || count($names)<1 || count($names)>5
            || count($names)!==count($paths) || count($names)!==count($sizes)
            || count($names)!==count($errors)) {
            throw new InvalidArgumentException('invalid_olist_exports');
        }
        $index=SvAmazonMarginOlistAdsIndex::path();
        $dir=dirname($index);
        if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) {
            throw new RuntimeException('olist_ads_storage_unavailable');
        }
        $incoming=$dir.'/incoming';
        if (!is_dir($incoming) && !mkdir($incoming,0770,true) && !is_dir($incoming)) {
            throw new RuntimeException('olist_ads_staging_unavailable');
        }
        $stored=[];
        $stageDirectories=[];
        try {
            $total=0;
            foreach ($names as $i=>$original) {
                if (($errors[$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK
                    || !is_string($original) || !is_string($paths[$i]??null)
                    || !is_numeric($sizes[$i])) {
                    throw new InvalidArgumentException('olist_export_upload_failed');
                }
                $name=basename(str_replace('\\','/',$original));
                if (strlen($name)>120 || !preg_match(
                    '/^anuncios[-_]\d{4}[-_]\d{2}[-_]\d{2}[-_]\d{2}[-_]\d{2}(?:[-_]\d{2})?\.(xls|xlsx|csv)$/i',
                    $name)) {
                    throw new InvalidArgumentException('invalid_olist_export_filename');
                }
                $size=(int)$sizes[$i];
                $total+=$size;
                if ($size<60 || $size>4*1024*1024 || $total>10*1024*1024) {
                    throw new InvalidArgumentException('olist_export_too_large');
                }
                $tmp=(string)$paths[$i];
                if (!is_uploaded_file($tmp)) {
                    throw new InvalidArgumentException('olist_export_not_http_upload');
                }
                $fh=fopen($tmp,'rb');
                $magic=$fh===false?'':(string)fread($fh,8);
                if ($fh!==false) fclose($fh);
                $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
                if (($ext==='xlsx' && !str_starts_with($magic,'PK'))
                    || ($ext==='xls' && substr($magic,0,8)!=="\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")
                    || ($ext==='csv' && (str_starts_with($magic,'PK')
                        || str_starts_with($magic,"\xd0\xcf")))) {
                    throw new InvalidArgumentException('olist_export_magic_mismatch');
                }
                // The parser must see the unmodified Olist filename to validate
                // the original export timestamp. Isolate it inside a private
                // random directory rather than prepending a random prefix.
                $stageDir=$incoming.'/'.bin2hex(random_bytes(12));
                if (!mkdir($stageDir,0700)) throw new RuntimeException('olist_export_staging_failed');
                $stageDirectories[]=$stageDir;
                $staged=$stageDir.'/'.$name;
                if (!move_uploaded_file($tmp,$staged)) {
                    throw new RuntimeException('olist_export_storage_failed');
                }
                chmod($staged,0640);
                $stored[]=$staged;
            }
            $python=(string)(getenv('SV_AMAZON_OLIST_ADS_PYTHON')
                ?: '/home/ubuntu/.shopvivaliz/venvs/amazon-margin-ads/bin/python');
            $script=dirname(__DIR__,2).'/scripts/amazon-margin/import-olist-ads.py';
            if (!is_file($script) || !is_file($python) || !is_executable($python)) {
                throw new RuntimeException('olist_ads_parser_not_installed');
            }
            $command=array_merge([$python,$script,'--output',$index],$stored);
            $pipes=[];
            $proc=proc_open($command,[
                0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],
            ],$pipes,dirname(__DIR__,2),null,['bypass_shell'=>true]);
            if (!is_resource($proc))throw new RuntimeException('olist_ads_parser_unavailable');
            fclose($pipes[0]);
            $stdout=(string)stream_get_contents($pipes[1],16384);
            fclose($pipes[1]);
            $stderr=(string)stream_get_contents($pipes[2],4096);
            fclose($pipes[2]);
            $exit=proc_close($proc);
            $parsed=json_decode($stdout,true);
            if ($exit!==0 || !is_array($parsed) || ($parsed['ok']??false)!==true) {
                $code=(string)($parsed['error']??'invalid_export');
                if (!preg_match('/^[a-z0-9_]{1,64}$/',$code))$code='invalid_export';
                throw new InvalidArgumentException($code);
            }
            return $parsed;
        } finally {
            foreach ($stored as $file) {
                if (is_file($file))@unlink($file);
            }
            foreach ($stageDirectories as $stageDir) {
                if (is_dir($stageDir))@rmdir($stageDir);
            }
        }
    }
}
