// Custom (Lebazone): Face ID / fingerprint / Windows Hello sign-in (WebAuthn passkeys).
import axios from 'axios';

const b64uToBuf = (s) => {
    s = String(s).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    const bin = atob(s);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out.buffer;
};
const bufToB64u = (buf) => {
    const u = new Uint8Array(buf);
    let s = '';
    for (let i = 0; i < u.length; i++) s += String.fromCharCode(u[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

const FLAG = 'lbzPasskeyOn';
export const passkeyOnThisDevice = () => { try { return localStorage.getItem(FLAG) === '1'; } catch (e) { return false; } };
const setFlag = (on) => { try { on ? localStorage.setItem(FLAG, '1') : localStorage.removeItem(FLAG); } catch (e) {} };

/** True when this phone/computer has Face ID, a fingerprint reader or Windows Hello the site may use. */
export async function passkeySupported() {
    try {
        return !!(window.isSecureContext && window.PublicKeyCredential
            && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable());
    } catch (e) { return false; }
}

/** What the phone calls it, for the button text. */
export function biometricName() {
    const ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod/i.test(ua)) return 'Face ID';
    if (/Macintosh/i.test(ua)) return 'Touch ID';
    if (/Android/i.test(ua)) return 'fingerprint / face';
    if (/Windows/i.test(ua)) return 'Windows Hello';
    return 'Face ID / fingerprint';
}

export function deviceLabel() {
    const ua = navigator.userAgent || '';
    const m = ua.match(/Android [\d.]+; ([^;)]+)/);
    if (m) return m[1].trim().slice(0, 60);
    if (/iPhone/i.test(ua)) return 'iPhone';
    if (/iPad/i.test(ua)) return 'iPad';
    if (/Macintosh/i.test(ua)) return 'Mac';
    if (/Windows/i.test(ua)) return 'Windows PC';
    return 'This device';
}

function friendly(e) {
    const n = e && e.name;
    if (n === 'NotAllowedError' || n === 'AbortError') return 'Cancelled';
    if (n === 'InvalidStateError') return 'Face ID is already on for this device';
    if (n === 'SecurityError') return 'Face ID needs the secure site address (https://admin.lebazone.store)';
    return (e && (e.response?.data?.message || e.message)) || 'Something went wrong';
}

/** Logged in: create a passkey on this device. Returns {ok, message}. */
export async function registerPasskey(apiUrl) {
    try {
        const r = await axios.get(apiUrl + '/passkey/register_options');
        if (r.data.status !== 1) return { ok: false, message: r.data.message };
        const { key, options } = r.data.data;
        const pk = options.publicKey;
        pk.challenge = b64uToBuf(pk.challenge);
        pk.user.id = b64uToBuf(pk.user.id);
        (pk.excludeCredentials || []).forEach(c => { c.id = b64uToBuf(c.id); });
        const cred = await navigator.credentials.create({ publicKey: pk });
        const res = await axios.post(apiUrl + '/passkey/register', {
            key,
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            attestationObject: bufToB64u(cred.response.attestationObject),
            device_name: deviceLabel(),
        });
        if (res.data.status === 1) setFlag(true);
        return { ok: res.data.status === 1, message: res.data.message };
    } catch (e) {
        if (e && e.name === 'InvalidStateError') setFlag(true);
        return { ok: false, message: friendly(e) };
    }
}

/**
 * Login page: ask the phone for its passkey and sign in.
 * Resolves with the same response body as the password login (so the page's normal success code runs).
 */
export async function loginWithPasskey(apiUrl, extra = {}) {
    const r = await axios.get(apiUrl + '/passkey/login_options');
    if (r.data.status !== 1) return r.data;
    const { key, options } = r.data.data;
    const pk = options.publicKey;
    pk.challenge = b64uToBuf(pk.challenge);
    (pk.allowCredentials || []).forEach(c => { c.id = b64uToBuf(c.id); });
    let cred;
    try {
        cred = await navigator.credentials.get({ publicKey: pk });
    } catch (e) {
        return { status: 0, message: friendly(e), cancelled: e && (e.name === 'NotAllowedError' || e.name === 'AbortError') };
    }
    const res = await axios.post(apiUrl + '/passkey/login', {
        key,
        id: bufToB64u(cred.rawId),
        clientDataJSON: bufToB64u(cred.response.clientDataJSON),
        authenticatorData: bufToB64u(cred.response.authenticatorData),
        signature: bufToB64u(cred.response.signature),
        userHandle: cred.response.userHandle ? bufToB64u(cred.response.userHandle) : '',
        fcm_token: window.panelFcmToken || '',
        language_code: localStorage.getItem('lang') || window.appLocale || 'en',
        ...extra,
    });
    if (res.data.status === 1) setFlag(true);
    return res.data;
}

export async function listPasskeys(apiUrl) {
    const r = await axios.get(apiUrl + '/passkey/list');
    return r.data.status === 1 ? (r.data.data || []) : [];
}

export async function removePasskey(apiUrl, id, isThisDevice = false) {
    await axios.post(apiUrl + '/passkey/delete', { id });
    if (isThisDevice) setFlag(false);
}
