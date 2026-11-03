<template>
    <!-- Custom (Lebazone): turn on Face ID / fingerprint sign-in for this account on this device. -->
    <div v-if="supported" class="pkc mb-3" :class="{ on: onHere }">
        <div class="pkc-ic">{{ onHere ? '✅' : '🔐' }}</div>
        <div class="pkc-t">
            <b v-if="!onHere">Sign in with {{ bio }}</b>
            <b v-else>{{ bio }} sign-in is on</b>
            <div class="small">
                <template v-if="!onHere">No more typing your password on this device · ادخل بالوجه أو البصمة
                    <div v-if="isApple" class="mt-1 pkc-note">iPhone will say <b>"Save a passkey"</b> — that is Face ID. Tap <b>Continue</b> and look at the phone.</div>
                </template>
                <template v-else>Next time tap "Sign in with {{ bio }}" on the login page</template>
            </div>
            <div v-if="devices.length && showList" class="pkc-list mt-2">
                <div v-for="d in devices" :key="d.id" class="d-flex align-items-center gap-2 small py-1">
                    <span>📱 {{ d.device_name || 'Device' }}</span>
                    <span class="text-muted">· added {{ String(d.created_at || '').slice(0, 10) }}</span>
                    <button class="btn btn-link btn-sm text-danger p-0 ms-auto" @click="remove(d)">Remove</button>
                </div>
            </div>
        </div>
        <div class="d-flex flex-column gap-1 align-items-end">
            <button v-if="!onHere" class="btn btn-light btn-sm fw-bold" :disabled="busy" @click="turnOn">Turn on</button>
            <button v-if="devices.length" class="btn btn-link btn-sm pkc-link p-0" @click="toggleList">{{ showList ? 'Hide' : 'Devices (' + devices.length + ')' }}</button>
        </div>
    </div>
</template>

<script>
import { passkeySupported, passkeyOnThisDevice, biometricName, registerPasskey, listPasskeys, removePasskey, deviceLabel } from '../utils/passkey.js';

export default {
    data() { return { supported: false, onHere: passkeyOnThisDevice(), bio: biometricName(), isApple: /iPhone|iPad|iPod|Macintosh/i.test(navigator.userAgent), busy: false, devices: [], showList: false }; },
    async mounted() {
        this.supported = await passkeySupported();
        if (this.supported) this.load();
    },
    methods: {
        async load() { try { this.devices = await listPasskeys(this.$apiUrl); } catch (e) { this.devices = []; } },
        toggleList() { this.showList = !this.showList; },
        async turnOn() {
            this.busy = true;
            const r = await registerPasskey(this.$apiUrl);
            this.busy = false;
            if (r.ok) { this.onHere = true; this.showMessage('success', r.message); this.load(); }
            else if (r.message !== 'Cancelled') this.showError(r.message);
            else this.onHere = passkeyOnThisDevice();
        },
        async remove(d) {
            if (!window.confirm('Remove ' + this.bio + ' sign-in for "' + (d.device_name || 'device') + '"?')) return;
            const here = d.device_name === deviceLabel();
            await removePasskey(this.$apiUrl, d.id, here);
            if (here) this.onHere = false;
            this.load();
        },
    },
};
</script>

<style scoped>
.pkc { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 14px; background: linear-gradient(135deg, #1F2937, #111827); color: #fff; }
.pkc.on { background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; }
.pkc-ic { font-size: 1.6rem; }
.pkc-note { font-size: .78rem; opacity: .85; }
.pkc-t { flex: 1; min-width: 0; }
.pkc-link { color: inherit; opacity: .8; }
.pkc-list { border-top: 1px solid rgba(255, 255, 255, .2); }
.pkc.on .pkc-list { border-top-color: #BBF7D0; }
</style>
