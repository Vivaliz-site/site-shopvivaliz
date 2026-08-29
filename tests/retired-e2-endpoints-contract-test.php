<?php
$root = dirname(__DIR__);
$retired = ['137.131.156.17','136.248.69.116','10.0.1.13','10.0.1.203','shopvivaliz-ai','shopvivaliz-micro-2'];
$patterns = [
 $root.'/.github/workflows/*.yml', $root.'/.github/workflows/*.yaml',
 $root.'/scripts/*', $root.'/ops/*', $root.'/config/*',
 $root.'/mcp-servers.json',
];
$violations=[];
foreach($patterns as $pattern){
 foreach(glob($pattern) ?: [] as $path){
  if(!is_file($path)) continue;
  $body=file_get_contents($path);
  foreach($retired as $token){
   if(strpos($body,$token)!==false){
    $violations[]=str_replace('\\','/',substr($path,strlen($root)+1)).':'.$token;
    break;
   }
  }
 }
}
$violations=array_values(array_unique($violations)); sort($violations);
if($violations){ fwrite(STDERR,"Retired E2 endpoint found in active executable/config path:\n".implode("\n",$violations)."\n"); exit(1); }
echo "retired-e2-endpoints-contract: ok\n";
