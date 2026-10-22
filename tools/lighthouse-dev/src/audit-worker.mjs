import { audit } from './audit.mjs';
import { SafetyError } from './policy.mjs';

const controller = new AbortController();
const timer = setTimeout(() => controller.abort(), 115000);
process.on('unhandledRejection', () => controller.abort());
for (const event of ['SIGINT', 'SIGTERM', 'disconnect']) process.once(event, () => controller.abort());
const send = message => new Promise(resolve => { if (process.connected) process.send(message, () => resolve()); else resolve(); });
process.once('message', async config => {
  try {
    const result = await audit(config, controller.signal);
    await send({ ok: true, result });
  } catch (error) {
    await send({ ok: false, error: error instanceof SafetyError ? error.code : 'audit-failed', diagnostic: error.diagnostic || null });
  } finally {
    clearTimeout(timer);
    process.disconnect?.();
    process.exit(0);
  }
});
