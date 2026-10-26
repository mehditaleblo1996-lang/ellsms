// Registers the proof-of-work worker for the self-hosted CAPTCHA (app/Captcha.php). The "external"
// ALTCHA build ships no inline workers, so it runs under the panel's script-src 'self' CSP.
const workerUrl = new URL('../vendor/altcha-3.2.4/pbkdf2.js', import.meta.url);
globalThis.$altcha.algorithms.set('PBKDF2/SHA-256', () => new Worker(workerUrl));
