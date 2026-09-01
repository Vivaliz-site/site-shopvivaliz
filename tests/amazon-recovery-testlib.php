<?php
declare(strict_types=1);
function ar_t_assert(bool $condition,string $message): void { if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }
function ar_t_eq(mixed $expected,mixed $actual,string $message): void { if($expected!==$actual){fwrite(STDERR,"FAIL: {$message}\nEXPECTED: ".var_export($expected,true)."\nACTUAL: ".var_export($actual,true)."\n");exit(1);} }
function ar_t_ok(string $name): void { fwrite(STDOUT,"PASS: {$name}\n"); }
