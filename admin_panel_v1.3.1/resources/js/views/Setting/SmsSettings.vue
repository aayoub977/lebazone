<template>
    <div>
        <div class="page-heading">
            <div class="page-head">
                <h3 class="page-head-title">{{ __('sms_settings') }}</h3>
                <router-link to="/settings"
                    class="btn btn-outline-secondary ms-auto d-inline-flex align-items-center gap-1">
                    <ArrowLeft :size="16" /> {{ __('back') }}
                </router-link>
            </div>

            <section class="section">
                <form method="post" @submit.prevent="saveRecord">
                    <!-- Custom (Hader): WhatsApp Business -->
                    <div class="card mb-4" :class="{ 'border-success': sms_settings.whatsapp_enabled === '1' }">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <h4 class="card-title mb-0">WhatsApp</h4>
                                <span class="badge bg-success-subtle text-success">Sent first, SMS as backup</span>
                            </div>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox"
                                    :checked="sms_settings.whatsapp_enabled === '1'"
                                    @change="sms_settings.whatsapp_enabled = $event.target.checked ? '1' : '0'">
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                OTP codes and order updates go out on WhatsApp (Meta WhatsApp Business Platform).
                                Create one approved message template per message type, with the same name — the list is in
                                <code>local-dev/WHATSAPP_TEMPLATES.md</code>.
                                <a href="https://business.facebook.com/wa/manage/home/" target="_blank" rel="noopener">Open WhatsApp Manager</a>
                            </p>
                            <div class="row">
                                <div class="col-md-6 form-group mb-3">
                                    <label>Permanent access token</label>
                                    <input type="text" class="form-control"
                                        :value="shouldHideConfidential ? '' : sms_settings.whatsapp_access_token"
                                        @input="!shouldHideConfidential && (sms_settings.whatsapp_access_token = $event.target.value)"
                                        placeholder="EAAG...">
                                </div>
                                <div class="col-md-6 form-group mb-3">
                                    <label>Phone number ID</label>
                                    <input type="text" class="form-control" v-model="sms_settings.whatsapp_phone_number_id" placeholder="e.g. 1234567890">
                                </div>
                                <div class="col-md-4 form-group mb-3">
                                    <label>Template languages</label>
                                    <input type="text" class="form-control" v-model="sms_settings.whatsapp_languages" placeholder="en,ar">
                                    <small class="text-muted">Codes your templates exist in; the first is the fallback.</small>
                                </div>
                                <div class="col-md-4 form-group mb-3">
                                    <label>If WhatsApp fails</label>
                                    <select class="form-select" v-model="sms_settings.whatsapp_sms_fallback">
                                        <option value="1">Send by SMS instead</option>
                                        <option value="0">Don't send anything</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group mb-3">
                                    <label>Chat button number (website)</label>
                                    <input type="text" class="form-control" v-model="sms_settings.whatsapp_chat_number" placeholder="+961 70 123 456">
                                    <small class="text-muted">Shows a WhatsApp chat button on the website. Leave empty to hide it.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Twilio -->
                        <div class="col-md-6 mb-4">
                            <div class="card h-100" :class="{ 'border-primary': sms_settings.sms_gateway === 'twilio' }">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div class="form-check form-switch d-flex align-items-center gap-2 m-0 ps-0">
                                        <h4 class="card-title mb-0">{{ __('twilio') }}</h4>
                                        <input class="form-check-input m-0 float-none" type="checkbox" role="switch"
                                            :checked="sms_settings.sms_gateway === 'twilio'"
                                            @change="toggleGateway('twilio', $event.target.checked)">
                                    </div>
                                    <a :href="dashboards.twilio" target="_blank" rel="noopener" class="small text-muted">
                                        <i class="fa fa-external-link-alt"></i> {{ __('setup_sms_gateway_hint') }}
                                    </a>
                                </div>
                                <div class="card-body">
                                    <div class="form-group">
                                        <label>{{ __('twilio_sid') }}</label>
                                        <input type="text" class="form-control"
                                            :value="shouldHideConfidential ? '' : sms_settings.twilio_sid"
                                            @input="!shouldHideConfidential && (sms_settings.twilio_sid = $event.target.value)"
                                            :readonly="shouldHideConfidential"
                                            :placeholder="shouldHideConfidential ? __('demo_mode') : 'Enter Twilio SID'">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ __('twilio_auth_token') }}</label>
                                        <input type="text" class="form-control"
                                            :value="shouldHideConfidential ? '' : sms_settings.twilio_auth_token"
                                            @input="!shouldHideConfidential && (sms_settings.twilio_auth_token = $event.target.value)"
                                            :readonly="shouldHideConfidential"
                                            :placeholder="shouldHideConfidential ? __('demo_mode') : 'Enter Twilio Auth Token'">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ __('twilio_phone_number') }}</label>
                                        <input type="text" class="form-control" v-model="sms_settings.twilio_phone_number" placeholder="Enter Twilio Phone Number">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- MSG91 -->
                        <div class="col-md-6 mb-4">
                            <div class="card h-100" :class="{ 'border-primary': sms_settings.sms_gateway === 'msg91' }">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div class="form-check form-switch d-flex align-items-center gap-2 m-0 ps-0">
                                        <h4 class="card-title mb-0">MSG91</h4>
                                        <input class="form-check-input m-0 float-none" type="checkbox" role="switch"
                                            :checked="sms_settings.sms_gateway === 'msg91'"
                                            @change="toggleGateway('msg91', $event.target.checked)">
                                    </div>
                                    <a :href="dashboards.msg91" target="_blank" rel="noopener" class="small text-muted">
                                        <i class="fa fa-external-link-alt"></i> {{ __('setup_sms_gateway_hint') }}
                                    </a>
                                </div>
                                <div class="card-body">
                                    <div class="form-group">
                                        <label>{{ __('msg91_auth_key') }}</label>
                                        <input type="text" class="form-control"
                                            :value="shouldHideConfidential ? '' : sms_settings.msg91_auth_key"
                                            @input="!shouldHideConfidential && (sms_settings.msg91_auth_key = $event.target.value)"
                                            :readonly="shouldHideConfidential"
                                            :placeholder="shouldHideConfidential ? __('demo_mode') : 'Enter MSG91 Auth Key'">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ __('msg91_sender_id') }}</label>
                                        <input type="text" class="form-control" v-model="sms_settings.msg91_sender_id" placeholder="Enter MSG91 Sender ID (DLT)">
                                    </div>
                                    <p class="text-muted small mb-0">{{ __('msg91_dlt_hint') }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Fast2SMS -->
                        <div class="col-md-6 mb-4">
                            <div class="card h-100" :class="{ 'border-primary': sms_settings.sms_gateway === 'fast2sms' }">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div class="form-check form-switch d-flex align-items-center gap-2 m-0 ps-0">
                                        <h4 class="card-title mb-0">Fast2SMS</h4>
                                        <input class="form-check-input m-0 float-none" type="checkbox" role="switch"
                                            :checked="sms_settings.sms_gateway === 'fast2sms'"
                                            @change="toggleGateway('fast2sms', $event.target.checked)">
                                    </div>
                                    <a :href="dashboards.fast2sms" target="_blank" rel="noopener" class="small text-muted">
                                        <i class="fa fa-external-link-alt"></i> {{ __('setup_sms_gateway_hint') }}
                                    </a>
                                </div>
                                <div class="card-body">
                                    <div class="form-group">
                                        <label>{{ __('fast2sms_api_key') }}</label>
                                        <input type="text" class="form-control"
                                            :value="shouldHideConfidential ? '' : sms_settings.fast2sms_api_key"
                                            @input="!shouldHideConfidential && (sms_settings.fast2sms_api_key = $event.target.value)"
                                            :readonly="shouldHideConfidential"
                                            :placeholder="shouldHideConfidential ? __('demo_mode') : 'Enter Fast2SMS API Key'">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ __('fast2sms_sender_id') }}</label>
                                        <input type="text" class="form-control" v-model="sms_settings.fast2sms_sender_id" placeholder="Enter Fast2SMS Sender ID (DLT)">
                                    </div>
                                    <p class="text-muted small mb-0">{{ __('fast2sms_hint') }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- 2Factor -->
                        <div class="col-md-6 mb-4">
                            <div class="card h-100" :class="{ 'border-primary': sms_settings.sms_gateway === 'twofactor' }">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div class="form-check form-switch d-flex align-items-center gap-2 m-0 ps-0">
                                        <h4 class="card-title mb-0">2Factor</h4>
                                        <input class="form-check-input m-0 float-none" type="checkbox" role="switch"
                                            :checked="sms_settings.sms_gateway === 'twofactor'"
                                            @change="toggleGateway('twofactor', $event.target.checked)">
                                    </div>
                                    <a :href="dashboards.twofactor" target="_blank" rel="noopener" class="small text-muted">
                                        <i class="fa fa-external-link-alt"></i> {{ __('setup_sms_gateway_hint') }}
                                    </a>
                                </div>
                                <div class="card-body">
                                    <div class="form-group">
                                        <label>{{ __('twofactor_api_key') }}</label>
                                        <input type="text" class="form-control"
                                            :value="shouldHideConfidential ? '' : sms_settings.twofactor_api_key"
                                            @input="!shouldHideConfidential && (sms_settings.twofactor_api_key = $event.target.value)"
                                            :readonly="shouldHideConfidential"
                                            :placeholder="shouldHideConfidential ? __('demo_mode') : 'Enter 2Factor API Key'">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ __('twofactor_sender_id') }}</label>
                                        <input type="text" class="form-control" v-model="sms_settings.twofactor_sender_id" placeholder="Enter 2Factor Sender ID (DLT)">
                                    </div>
                                    <p class="text-muted small mb-0">{{ __('twofactor_hint') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group d-flex justify-content-end">
                        <b-button type="submit" variant="primary" :disabled="isLoading || shouldHideConfidential"
                            v-if="$can('manage_sms_settings') && ($isDemo != 1 || (login_user && login_user.id === 1))">
                            {{ __('update') }}
                            <b-spinner v-if="isLoading" small></b-spinner>
                        </b-button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>

<script>
import axios from "axios";
import { ArrowLeft } from 'lucide-vue-next';
import Auth from '../../Auth.js';
import UnsavedChanges from '../../mixins/UnsavedChanges.js';

export default {
    mixins: [UnsavedChanges],
    components: { ArrowLeft },
    data() {
        return {
            isLoading: false,
            login_user: Auth.user,
            // Provider dashboards (open in a new tab).
            dashboards: {
                twilio: 'https://console.twilio.com/',
                msg91: 'https://control.msg91.com/',
                fast2sms: 'https://www.fast2sms.com/dashboard/',
                twofactor: 'https://2factor.in/',
            },
            sms_settings: {
                sms_gateway: 'twilio',
                twilio_sid: '',
                twilio_auth_token: '',
                twilio_phone_number: '',
                msg91_auth_key: '',
                msg91_sender_id: '',
                fast2sms_api_key: '',
                fast2sms_sender_id: '',
                twofactor_api_key: '',
                twofactor_sender_id: '',
                whatsapp_enabled: '0',
                whatsapp_access_token: '',
                whatsapp_phone_number_id: '',
                whatsapp_languages: 'en,ar',
                whatsapp_sms_fallback: '1',
                whatsapp_chat_number: '',
            },
        };
    },
    created() {
        this.getSmsMethods();
    },
    computed: {
        // Hide confidential SMS secrets in demo mode, except for auth user id 1.
        shouldHideConfidential() {
            return this.$isDemo == 1 && (!this.login_user || this.login_user.id !== 1);
        },
    },
    methods: {
        // Tracked state for the UnsavedChanges guard.
        formState() {
            return this.sms_settings;
        },
        // Only one gateway is active. Turning a card on activates it; turning the
        // active one off leaves none active.
        toggleGateway(key, enabled) {
            if (enabled) {
                this.sms_settings.sms_gateway = key;
            } else if (this.sms_settings.sms_gateway === key) {
                this.sms_settings.sms_gateway = '';
            }
        },
        getSmsMethods() {
            axios.get(this.$apiUrl + '/sms_settings').then((response) => {
                const d = response.data.data;
                if (d) {
                    // Empty = all gateways off; do NOT fall back to twilio.
                    this.sms_settings.sms_gateway = d.sms_gateway ?? '';
                    this.sms_settings.twilio_sid = d.twilio_sid || '';
                    this.sms_settings.twilio_auth_token = d.twilio_auth_token || '';
                    this.sms_settings.twilio_phone_number = d.twilio_phone_number || '';
                    this.sms_settings.msg91_auth_key = d.msg91_auth_key || '';
                    this.sms_settings.msg91_sender_id = d.msg91_sender_id || '';
                    this.sms_settings.fast2sms_api_key = d.fast2sms_api_key || '';
                    this.sms_settings.fast2sms_sender_id = d.fast2sms_sender_id || '';
                    this.sms_settings.twofactor_api_key = d.twofactor_api_key || '';
                    this.sms_settings.twofactor_sender_id = d.twofactor_sender_id || '';
                    this.sms_settings.whatsapp_enabled = d.whatsapp_enabled === '1' ? '1' : '0';
                    this.sms_settings.whatsapp_access_token = d.whatsapp_access_token || '';
                    this.sms_settings.whatsapp_phone_number_id = d.whatsapp_phone_number_id || '';
                    this.sms_settings.whatsapp_languages = d.whatsapp_languages || 'en,ar';
                    this.sms_settings.whatsapp_sms_fallback = d.whatsapp_sms_fallback === '0' ? '0' : '1';
                    this.sms_settings.whatsapp_chat_number = d.whatsapp_chat_number || '';
                }
                // Snapshot the loaded settings as the "clean" baseline for the guard.
                this.captureFormBaseline();
            });
        },
        saveRecord() {
            this.isLoading = true;
            const formData = new FormData();
            for (const key in this.sms_settings) {
                formData.append(key, this.sms_settings[key] ?? '');
            }
            const vm = this;
            axios.post(this.$apiUrl + '/sms_settings/save', formData).then(res => {
                const data = res.data;
                if (data.status === 1) {
                    this.showMessage("success", data.message);
                    this.getSmsMethods();
                    // Mark clean after a successful save (page stays put).
                    this.captureFormBaseline();
                } else {
                    this.showError(data.message);
                }
            }).catch(error => {
                this.showError(error.response?.data?.message || error.message || __('something_went_wrong'));
            }).finally(() => { vm.isLoading = false; });
        },
    },
};
</script>
