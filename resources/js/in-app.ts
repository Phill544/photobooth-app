// Detecting in-app browsers (Instagram, Facebook, etc.) that block or break
// getUserMedia, so we can warn guests before they hit a dead camera. This is
// only a nicety: since iOS 26 some Facebook webviews report as plain Safari,
// so the real safety net is branching on the getUserMedia rejection at Start.

// Ordered — Messenger's UA also contains FBAN, so it must be tested first.
const IN_APP: ReadonlyArray<{ app: string; re: RegExp }> = [
    { app: 'Instagram', re: /Instagram/i },
    { app: 'Messenger', re: /FBAN\/MessengerForiOS/i },
    { app: 'Facebook', re: /FBAN|FBAV|FB_IAB/i },
    { app: 'TikTok', re: /BytedanceWebview|musical_ly|Trill/i },
    { app: 'Snapchat', re: /Snapchat/i },
    { app: 'LINE', re: /\bLine\//i },
    { app: 'WeChat', re: /MicroMessenger/i },
];

export function detectInApp(userAgent: string): string | null {
    return IN_APP.find(({ re }) => re.test(userAgent))?.app ?? null;
}

// Forces Chrome on Android from a real tap; if Chrome is absent the fallback loads.
export function androidChromeIntent(url: string): string {
    const u = new URL(url);
    return `intent://${u.host}${u.pathname}${u.search}#Intent;scheme=https;`
        + `package=com.android.chrome;`
        + `S.browser_fallback_url=${encodeURIComponent(url)};end`;
}

export const isIOS = (): boolean =>
    /iP(hone|od|ad)/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

export function cameraSupported(): boolean {
    return !!navigator.mediaDevices?.getUserMedia && window.isSecureContext;
}
