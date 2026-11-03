<template>
    <!-- Custom (Lebazone): use the device camera as a barcode scanner (phone, tablet or webcam). -->
    <span class="cam-scan">
        <button type="button" class="btn" :class="[open ? 'btn-danger' : (big ? 'btn-dark' : 'btn-outline-dark'), big ? 'btn-lg cam-big' : '']" @click="toggle" :title="open ? 'Stop camera' : 'Scan with camera'">
            {{ open ? '✕ Stop camera' : (label || '📷 Camera') }}
        </button>
        <div v-if="open" class="cam-box">
            <video ref="video" playsinline muted autoplay></video>
            <div class="cam-aim"></div>
            <div class="cam-hint">{{ hint }}</div>
            <select v-if="cameras.length > 1" v-model="deviceId" class="form-select form-select-sm cam-pick" @change="restart">
                <option v-for="c in cameras" :key="c.deviceId" :value="c.deviceId">{{ c.label || 'Camera' }}</option>
            </select>
        </div>
    </span>
</template>

<script>
const FORMATS = ['code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_39', 'qr_code', 'itf'];

export default {
    props: { big: { type: Boolean, default: false }, label: { type: String, default: '' } },
    emits: ['scan'],
    data() {
        return { open: false, hint: 'Point the camera at a barcode', stream: null, timer: null, detector: null,
            zx: null, zxControls: null, last: '', lastAt: 0, cameras: [], deviceId: '' };
    },
    beforeUnmount() { this.stop(); },
    methods: {
        async toggle() { import('../utils/sounds.js').then(m => m.unlockAudio()).catch(() => {}); this.open ? this.stop() : this.start(); },
        emitCode(text) {
            const code = String(text || '').trim();
            if (!code) return;
            const now = Date.now();
            if (code === this.last && now - this.lastAt < 2500) return; // same label still in view
            this.last = code; this.lastAt = now;
            if (navigator.vibrate) navigator.vibrate(60);
            this.$emit('scan', code);
        },
        async start() {
            if (!navigator.mediaDevices?.getUserMedia) {
                this.$root.showError ? this.$root.showError('This browser cannot use the camera (open the site over https).') : alert('Camera not available');
                return;
            }
            this.open = true;
            await this.$nextTick();
            try {
                const constraints = { video: this.deviceId ? { deviceId: { exact: this.deviceId } }
                    : { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false };
                this.stream = await navigator.mediaDevices.getUserMedia(constraints);
                const v = this.$refs.video;
                // Lebazone: keep the lens focusing (paper sheets are held close and at angles).
                try { await this.stream.getVideoTracks()[0].applyConstraints({ advanced: [{ focusMode: 'continuous' }] }); } catch (e) {}
                v.srcObject = this.stream;
                await v.play().catch(() => {});
                try {
                    this.cameras = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput');
                    if (!this.deviceId) this.deviceId = this.stream.getVideoTracks()[0]?.getSettings()?.deviceId || '';
                } catch (e) {}

                // 1) Built-in detector (Chrome on Android, Safari, macOS) - fast and light.
                if ('BarcodeDetector' in window) {
                    try {
                        const supported = await window.BarcodeDetector.getSupportedFormats();
                        this.detector = new window.BarcodeDetector({ formats: FORMATS.filter(f => supported.includes(f)) });
                        const loop = async () => {
                            if (!this.open) return;
                            try {
                                const res = await this.detector.detect(v);
                                if (res && res.length) this.emitCode(res[0].rawValue);
                            } catch (e) {}
                            this.timer = setTimeout(loop, 180);
                        };
                        loop();
                        return;
                    } catch (e) { this.detector = null; }
                }
                // 2) Fallback for Windows / Firefox: ZXing in JavaScript.
                const { BrowserMultiFormatReader } = await import('@zxing/browser');
                const { DecodeHintType, BarcodeFormat } = await import('@zxing/library');
                const hints = new Map();
                hints.set(DecodeHintType.TRY_HARDER, true);
                hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.QR_CODE, BarcodeFormat.CODE_128, BarcodeFormat.EAN_13,
                    BarcodeFormat.EAN_8, BarcodeFormat.UPC_A, BarcodeFormat.UPC_E, BarcodeFormat.CODE_39, BarcodeFormat.ITF]);
                this.zx = new BrowserMultiFormatReader(hints, { delayBetweenScanAttempts: 120 });
                this.zxControls = await this.zx.decodeFromVideoElement(v, (result) => { if (result) this.emitCode(result.getText()); });
            } catch (e) {
                this.hint = (e && e.name === 'NotAllowedError') ? 'Camera permission was refused - allow it in the browser settings' : ('Camera error: ' + (e.message || e));
            }
        },
        stop() {
            this.open = false;
            clearTimeout(this.timer);
            try { this.zxControls && this.zxControls.stop(); } catch (e) {}
            this.zxControls = null;
            if (this.stream) this.stream.getTracks().forEach(t => t.stop());
            this.stream = null;
        },
        async restart() { this.stop(); await this.$nextTick(); this.start(); },
    },
};
</script>

<style scoped>
.cam-scan { display: contents; }
.cam-big { padding-left: 22px; padding-right: 22px; font-weight: 700; }
.cam-box { position: relative; width: 100%; max-width: 520px; margin-top: 10px; border-radius: 12px; overflow: hidden; background: #000; flex-basis: 100%; }
.cam-box video { width: 100%; display: block; max-height: 60vh; object-fit: cover; }
.cam-aim { position: absolute; left: 10%; right: 10%; top: 38%; height: 24%; border: 3px solid rgba(220, 38, 38, .9); border-radius: 8px; box-shadow: 0 0 0 9999px rgba(0, 0, 0, .25); }
.cam-hint { position: absolute; bottom: 8px; left: 0; right: 0; text-align: center; color: #fff; font-size: .9rem; text-shadow: 0 1px 3px #000; }
.cam-pick { position: absolute; top: 8px; right: 8px; width: auto; max-width: 60%; opacity: .9; }
</style>
