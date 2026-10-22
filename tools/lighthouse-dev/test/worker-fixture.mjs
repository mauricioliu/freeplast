import { spawn } from 'node:child_process';
import { writeFile, rm } from 'node:fs/promises';
let owned;
process.once('message', async ({ mode, pidFile, profileDir }) => {
  if (mode === 'complete') { process.send({ ok: true, result: { synthetic: true } }, () => process.exit(0)); return; }
  if (mode === 'empty') { process.exit(0); return; }
  owned = spawn(process.execPath, ['-e', 'setInterval(()=>{},1000)'], { stdio: 'ignore', detached: false });
  await writeFile(pidFile, String(owned.pid));
  if (mode === 'crash') process.kill(process.pid, 'SIGKILL');
  else if (mode === 'disconnect') {
    process.once('disconnect', () => { owned.kill('SIGKILL'); setTimeout(async () => { await rm(profileDir, { recursive: true, force: true }); process.exit(0); }, 500); });
    setTimeout(() => { owned.kill('SIGKILL'); process.exit(0); }, 5000);
  } else process.once('SIGTERM', () => { owned.kill('SIGKILL'); process.exit(0); });
});
