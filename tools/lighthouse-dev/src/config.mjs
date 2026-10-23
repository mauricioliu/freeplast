export const timezone = 'America/Santiago';
// Puppeteer suppresses its automatic port when ANY --remote-debugging-* flag exists.
export const smokeBrowserArgs = ['--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1', '--no-proxy-server', '--lang=es', '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1'];

const common = {
  locale: 'es',
  onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo'],
  throttlingMethod: 'simulate',
  disableStorageReset: false,
  output: ['json', 'html'],
};
const userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';

export const profiles = {
  mobile: {
    ...common,
    formFactor: 'mobile',
    screenEmulation: { mobile: true, width: 412, height: 823, deviceScaleFactor: 1.75, disabled: false },
    emulatedUserAgent: 'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36',
    throttling: { rttMs: 150, throughputKbps: 1638.4, requestLatencyMs: 562.5, downloadThroughputKbps: 1474.56, uploadThroughputKbps: 675, cpuSlowdownMultiplier: 4 },
  },
  desktop: {
    ...common,
    formFactor: 'desktop',
    screenEmulation: { mobile: false, width: 1440, height: 900, deviceScaleFactor: 1, disabled: false },
    emulatedUserAgent: userAgent,
    throttling: { rttMs: 40, throughputKbps: 10240, requestLatencyMs: 0, downloadThroughputKbps: 0, uploadThroughputKbps: 0, cpuSlowdownMultiplier: 1 },
  },
};

// Lab-only: native Woo refresh on BFCache restore requires a forbidden POST.
export const auditConfig = {
  generation: 5, profiles,
  disabledFeatures: ['NetworkTimeServiceQuerying', 'AutofillServerCommunication', 'kPreconnectToSearch', 'kSearchEnginePreconnect2', 'BackForwardCache'],
};

export function lighthouseConfig(profile) {
  if (!Object.hasOwn(profiles, profile)) throw new Error('Unknown profile');
  return { extends: 'lighthouse:default', settings: structuredClone(profiles[profile]) };
}
