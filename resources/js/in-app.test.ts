import { describe, expect, it } from 'vitest';
import { androidChromeIntent, detectInApp } from './in-app';

const SAFARI_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36';

describe('detectInApp', () => {
    it('returns null for real mobile browsers', () => {
        expect(detectInApp(SAFARI_IOS)).toBeNull();
        expect(detectInApp(CHROME_ANDROID)).toBeNull();
    });

    it('spots the camera-hostile in-app browsers', () => {
        expect(detectInApp(SAFARI_IOS + ' Instagram 300.0')).toBe('Instagram');
        expect(detectInApp(CHROME_ANDROID + ' [FB_IAB/FB4A;FBAV/450.0.0;]')).toBe('Facebook');
        expect(detectInApp(SAFARI_IOS.replace('Safari/604.1', 'Snapchat/12.0'))).toBe('Snapchat');
        expect(detectInApp(CHROME_ANDROID + ' BytedanceWebview/d8a21c')).toBe('TikTok');
        expect(detectInApp('...MicroMessenger/8.0.5...')).toBe('WeChat');
    });

    it('labels Messenger before the generic Facebook family', () => {
        expect(detectInApp(SAFARI_IOS + ' [FBAN/MessengerForiOS;FBAV/450.0]')).toBe('Messenger');
    });

    it('anchors LINE on the slash so it does not match ordinary words', () => {
        expect(detectInApp(SAFARI_IOS + ' Line/13.5.0')).toBe('LINE');
        expect(detectInApp(CHROME_ANDROID + ' streamline online')).toBeNull();
    });

    it('cannot catch iOS-26 Facebook webviews that report as plain Safari', () => {
        // Documents why the getUserMedia error branch, not this detector, is the real safety net.
        const tokenless = SAFARI_IOS; // these builds ship no FBAN/FBAV token
        expect(detectInApp(tokenless)).toBeNull();
    });
});

describe('androidChromeIntent', () => {
    it('builds an intent that forces Chrome with an https fallback', () => {
        const intent = androidChromeIntent('https://booth.example.com/e/PARTY2');

        expect(intent).toBe(
            'intent://booth.example.com/e/PARTY2#Intent;scheme=https;' +
            'package=com.android.chrome;' +
            'S.browser_fallback_url=https%3A%2F%2Fbooth.example.com%2Fe%2FPARTY2;end',
        );
    });
});
