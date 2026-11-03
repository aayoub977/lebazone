// Custom (Lebazone): warehouse sounds. Phones block audio until the first tap, so
// unlockAudio() runs on the first touch/key and every sound after that plays.
let ctx = null;

export function unlockAudio() {
    try {
        ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
        if (ctx.state === 'suspended') ctx.resume();
        // A silent blip finishes the unlock on iPhone.
        const o = ctx.createOscillator(), g = ctx.createGain();
        g.gain.value = 0.0001; o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + 0.01);
    } catch (e) { /* no audio on this device */ }
}

export function listenForUnlock() {
    const once = () => { unlockAudio(); document.removeEventListener('pointerdown', once, true); document.removeEventListener('keydown', once, true); };
    document.addEventListener('pointerdown', once, true);
    document.addEventListener('keydown', once, true);
}

function tone(freq, at, dur, type = 'sine', vol = 0.3) {
    if (!ctx) unlockAudio();
    if (!ctx) return;
    const t = ctx.currentTime + at;
    const o = ctx.createOscillator(), g = ctx.createGain();
    o.type = type; o.frequency.setValueAtTime(freq, t);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol, t + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(ctx.destination);
    o.start(t); o.stop(t + dur + 0.02);
}

const buzz = ms => { try { navigator.vibrate && navigator.vibrate(ms); } catch (e) {} };

/** Product scanned correctly: short bright "ding-ding". */
export function soundOk() { tone(1046, 0, 0.09); tone(1568, 0.08, 0.14); buzz(40); }

/** Step finished (all products found / order on the shelf): rising chime. */
export function soundDone() { tone(784, 0, 0.12); tone(1046, 0.11, 0.12); tone(1318, 0.22, 0.12); tone(1568, 0.33, 0.28); buzz([60, 40, 60]); }

/** Wrong item / not allowed: loud low triple buzz + vibration. */
export function soundError() {
    tone(196, 0, 0.16, 'square', 0.35); tone(196, 0.2, 0.16, 'square', 0.35); tone(147, 0.4, 0.32, 'square', 0.35);
    buzz([150, 80, 150, 80, 250]);
}

/** Information (e.g. order opened): single soft tone. */
export function soundInfo() { tone(880, 0, 0.12, 'sine', 0.2); }
