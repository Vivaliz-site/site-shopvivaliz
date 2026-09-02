import { readFile } from 'node:fs/promises';

const supervisor = await readFile(new URL('../scripts/fredwin-desktop-commander-supervisor.ps1', import.meta.url), 'utf8');
let guardian = '';
try {
  guardian = await readFile(new URL('../scripts/fredwin-desktop-commander-task-guardian.ps1', import.meta.url), 'utf8');
} catch {
  throw new Error('missing independent Fred-Win Desktop Commander task guardian');
}

for (const needle of [
  "$GuardianTaskName = 'ShopVivaliz Desktop Commander Task Guardian'",
  'fredwin-desktop-commander-task-guardian.ps1',
  'Register-ScheduledTask -TaskName $GuardianTaskName',
  'Restore-PersistentTaskState',
  "'KillForRecoveryTest'",
]) {
  if (!supervisor.includes(needle)) throw new Error(`supervisor missing task-disable hardening: ${needle}`);
}

for (const needle of [
  "$TaskName = 'ShopVivaliz Desktop Commander 24h'",
  'Enable-ScheduledTask -TaskName $TaskName',
  'Start-ScheduledTask -TaskName $TaskName',
  'TASK_GUARDIAN_REENABLED=true',
]) {
  if (!guardian.includes(needle)) throw new Error(`guardian missing recovery behavior: ${needle}`);
}

for (const forbidden of ['Stop-RemoteProcesses', 'Stop-Process', 'taskkill', 'KillForRecoveryTest']) {
  if (guardian.includes(forbidden)) throw new Error(`guardian must not own provider lifecycle: ${forbidden}`);
}

console.log('fredwin-desktop-commander-task-guardian-contract: ok');
