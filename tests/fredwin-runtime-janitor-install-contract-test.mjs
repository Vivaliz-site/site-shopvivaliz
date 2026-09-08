import { readFile } from 'node:fs/promises';
const janitor = await readFile(new URL('../scripts/fredwin-runtime-janitor.ps1', import.meta.url), 'utf8');
for (const needle of [
  '[switch]$InstallTask',
  "'ShopVivaliz Runtime Janitor'",
  'New-ScheduledTaskTrigger -AtStartup',
  'New-TimeSpan -Minutes 5',
  'New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest',
  'Register-ScheduledTask',
  '$settings.Hidden = $true',
]) {
  if (!janitor.includes(needle)) throw new Error(`janitor missing persistence contract: ${needle}`);
}
console.log('fredwin-runtime-janitor-install-contract: ok');
