import { plan } from '../src/budget.mjs';
export const allocationInput = { planId: plan.id, planHash: plan.hash, operator: 'fixture', deadline: new Date(plan.deadline).toISOString() };
export const requestFor = (environment, captures = 6) => ({ purpose: 'baseline-a1', generation: 1, captures, hypothesis: 0, compatibility: { instrument: environment?.instrument || 'a'.repeat(64), configHash: environment?.configHash || 'b'.repeat(64), sourceHash: environment?.source?.sourceHash || 'c'.repeat(64) } });
export const runPlan = { id: plan.id, hash: plan.hash };
