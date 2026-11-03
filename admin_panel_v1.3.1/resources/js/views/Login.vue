<template>
    <div class="auth" :style="$panelLoginBackgroundImg ? { backgroundImage: `url(${$panelLoginBackgroundImg})` } : null">
        <div class="login-wrapper">
            <div class="auth-section">
                <div class="auth-card login-card">

                    <div class="lg-brand">
                        <img v-if="$appLogo != ''" :src="$storageUrl + $appLogo" class="lg-brand-logo" alt="" />
                        <img v-else :src="$baseUrl + '/images/logo.png'" class="lg-brand-logo" alt="" />
                    </div>

                    <h1 class="lg-title">{{ __('welcome_back') }}</h1>
                    <p class="lg-subtitle">{{ __('sign_in_to_continue_to_your_dashboard') }}</p>

                    <form @submit.prevent="loginCheck()" novalidate>
                        <label class="lg-label" for="login-email">{{ __('email_address') }}</label>
                        <div class="lg-field">
                            <component :is="icons.mail" :size="18" class="lg-field-icon" />
                            <input id="login-email" type="email" class="lg-input" placeholder="you@example.com"
                                autocomplete="email" required v-model="user.email">
                        </div>

                        <label class="lg-label" for="login-password">{{ __('password') }}</label>
                        <div class="lg-field">
                            <component :is="icons.key" :size="18" class="lg-field-icon" />
                            <input id="login-password" :type="showPassword ? 'text' : 'password'" class="lg-input has-trail"
                                :placeholder="__('enter_your_password')" autocomplete="current-password" required
                                v-model="user.password">
                            <button type="button" class="lg-eye" @click="showPassword = !showPassword"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'">
                                <component :is="showPassword ? icons.eye : icons.eyeOff" :size="17" />
                            </button>
                        </div>

                        <div class="lg-forgot">
                            <router-link to="/forgot-password">{{ __('forgot_password') }}</router-link>
                        </div>

                        <button type="submit" class="lg-btn lg-btn-primary" :disabled="isLoading">
                            <b-spinner v-if="isLoading" small label="Signing in" />
                            <template v-else>
                                {{ __('login') }}
                                <component :is="icons.arrowRight" :size="17" />
                            </template>
                        </button>
                        <!-- Custom (Lebazone): Face ID / fingerprint sign-in -->
                        <button v-if="pkSupported" type="button" class="lg-btn lg-btn-soft lbz-pk-btn" :disabled="isLoading" @click="passkeyLogin">
                            <span style="font-size:1.1em">🔐</span> Sign in with {{ pkName }}
                        </button>


                        <!-- Custom (Lebazone): one login for everyone (admins, packers, product entry, station, drivers) -->
                        <div class="text-center small mt-3 text-muted">New driver? <router-link to="/delivery_boy/register">Register here</router-link></div>

                    </form>

                    <div class="lg-copyright" v-html="copyrightDetails"></div>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { markRaw } from 'vue';
import axios from 'axios';
import Auth from '../Auth.js';
import { passkeySupported, loginWithPasskey, biometricName } from '../utils/passkey.js';
import { landingFor, finishLogin } from '../utils/landing.js';
import { Mail, KeyRound, Eye, EyeOff, ArrowRight, Truck, Store } from 'lucide-vue-next';

export default {
    components: {
    },
    data: function () {
        return {
            pkSupported: false, pkName: biometricName(),
            icons: {
                mail: markRaw(Mail),
                key: markRaw(KeyRound),
                eye: markRaw(Eye),
                eyeOff: markRaw(EyeOff),
                arrowRight: markRaw(ArrowRight),
                truck: markRaw(Truck),
                store: markRaw(Store),
            },
            isLoading: false,
            user: {
                email: (this.$isDemo === 1 || this.$isDemo === '1') ? 'admin@gmail.com' : '',
                password: (this.$isDemo === 1 || this.$isDemo === '1') ? '123456' : '',
                type: 1
            },
            showPassword: false,
            loggedUser: Auth.user,
            setting: "",
            copyrightDetails: window.copyrightDetails,
        };
    },

    mounted() {
        passkeySupported().then(v => { this.pkSupported = v; });
        if (this.loggedUser) {
            this.$router.push(landingFor(this.loggedUser)).catch(() => { });
        } else {
            this.$router.push('/login').catch(() => { });
        }
        let user_theme = sessionStorage.getItem("user-theme");
        this.userTheme = user_theme;
        document.body.className = user_theme;
    },
    methods: {

        passkeyLogin() {
            this.isLoading = true;
            loginWithPasskey(this.$apiUrl).then(data => {
                this.isLoading = false;
                if (data.status === 1) {
                    const to = finishLogin(data.data);
                    if (to) this.$router.push(to); else this.showError('Could not sign in');
                } else if (!data.cancelled) this.showError(data.message);
            }).catch(e => { this.isLoading = false; this.showError(e?.response?.data?.message || e.message); });
        },
        loginCheck: function () {
            let vm = this;
            this.isLoading = true;

            let url = this.$apiUrl + '/login';
            // Send the panel FCM token (set by welcome.blade after getToken) + the selected
            // language so the login API registers the device with the right language.
            let payload = Object.assign({}, this.user, {
                fcm_token: window.panelFcmToken || '',
                language_code: window.localStorage.getItem('lang') || window.appLocale || 'en',
            });
            axios.post(url, payload).then(res => {
                vm.isLoading = false;
                let data = res.data;
                if (data.status === 1) {
                    const to = finishLogin(data.data);
                    if (to) this.$router.push(to); else vm.showError('Could not sign in');

                } else {
                    vm.showError(data.message);
                }
            }).catch(error => {
                vm.isLoading = false;
                if (error.request.statusText) {
                    this.showError(error.request.statusText);
                } else if (error.message) {
                    this.showError(error.message);
                } else {
                    this.showError("Something went wrong!");
                }

            });
        }
    }
}
</script>
<style scoped>
/* Shared auth card styling lives in assets/css/custom/common.css (.lg-*). */
</style>
