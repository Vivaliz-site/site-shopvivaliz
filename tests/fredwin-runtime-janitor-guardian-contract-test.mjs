import { readFile } from 'node:fs/promises';
const guardian = await readFile(new URL('../scripts/fredwin-desktop-commander-task-guardian.ps1', import.meta.url), 'utf8');
for (const needle of [
  "$RuntimeJanitorScript = 'C:\\site-shopvivaliz\\scripts\\fredwin-runtime-janitor.ps1'",
  "'ShopVivaliz Runtime Janitor'",
  "'ShopVivaliz AI Session Orphan Guard'",
  'Enable-ScheduledTask -TaskName $name',
  'Start-ScheduledTask -TaskName $name',
  '-InstallTask',
  'RUNTIME_JANITOR_RECREATED=true',
]) {
  if (!guardian.includes(needle)) throw new Error(`guardian missing runtime cleanup protection: ${needle}`);
}
console.log('fredwin-runtime-janitor-guardian-contract: ok');
