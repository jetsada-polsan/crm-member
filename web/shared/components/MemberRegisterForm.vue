<template>
  <div class="auth-form-container">
    <div class="auth-notice">
      <svg
        class="auth-notice-icon"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.9"
        aria-hidden="true"
      >
        <circle cx="12" cy="12" r="9" />
        <path d="M12 8h.01M11 12h1v4h1" />
      </svg>
      <span> สามารถสมัครสมาชิกได้ทันที ประวัติยอดซื้อและคะแนนสะสมจะเชื่อมโยงเมื่อทีมงานยืนยันข้อมูลร้านค้าแล้ว </span>
    </div>

    <div v-if="displayError" class="auth-error" role="alert" aria-live="assertive">
      <svg
        class="auth-error-icon"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      >
        <circle cx="12" cy="12" r="9" />
        <path d="M12 8v4m0 4h.01" />
      </svg>
      <span>{{ displayError }}</span>
    </div>

    <form class="auth-form" novalidate @submit.prevent="onSubmit">
      <div class="auth-field">
        <label :for="`${idPrefix}-r-name`">
          ชื่อร้านค้า / ชื่อผู้ติดต่อ <span class="auth-req" aria-hidden="true">*</span>
        </label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="8" r="4" />
            <path d="M4 21v-2a7 7 0 0 1 14 0v2" />
          </svg>
          <input
            :id="`${idPrefix}-r-name`"
            ref="nameRef"
            v-model.trim="name"
            class="no-trailing-btn"
            required
            minlength="2"
            placeholder="เช่น ร้านบ้านสวนหมูกระทะ"
            aria-required="true"
          />
        </div>
      </div>

      <div class="auth-field">
        <label :for="`${idPrefix}-r-phone`">เบอร์โทรศัพท์ (กรอกอย่างน้อย 1 ช่องทางคู่กับอีเมล)</label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path
              d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07A19.5 19.5 0 0 1 5.15 12.8 19.8 19.8 0 0 1 2.08 4.11 2 2 0 0 1 4.06 2h3a2 2 0 0 1 2 1.72c.12.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.58 2.81.7A2 2 0 0 1 22 16.92Z"
            />
          </svg>
          <input
            :id="`${idPrefix}-r-phone`"
            ref="phoneRef"
            v-model.trim="phone"
            class="no-trailing-btn"
            type="tel"
            inputmode="tel"
            autocomplete="tel"
            placeholder="08x-xxx-xxxx"
          />
        </div>
      </div>

      <div class="auth-field">
        <label :for="`${idPrefix}-r-email`">อีเมล (ไม่บังคับหากกรอกเบอร์โทรศัพท์แล้ว)</label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="5" width="18" height="14" rx="2.5" />
            <path d="m3 7 9 6 9-6" />
          </svg>
          <input
            :id="`${idPrefix}-r-email`"
            v-model.trim="email"
            class="no-trailing-btn"
            type="email"
            autocomplete="email"
            placeholder="shop@example.com"
          />
        </div>
      </div>

      <div class="auth-field">
        <label :for="`${idPrefix}-r-pass`">
          รหัสผ่าน (อย่างน้อย 12 ตัวอักษร) <span class="auth-req" aria-hidden="true">*</span>
        </label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="5" y="10" width="14" height="11" rx="2.5" />
            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
          </svg>
          <input
            :id="`${idPrefix}-r-pass`"
            ref="passRef"
            v-model="password"
            :type="showPass ? 'text' : 'password'"
            required
            minlength="12"
            autocomplete="new-password"
            placeholder="ตั้งรหัสผ่านอย่างน้อย 12 ตัวอักษร"
            aria-required="true"
          />
          <button
            type="button"
            class="auth-eye"
            :title="showPass ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'"
            :aria-label="showPass ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'"
            :aria-pressed="String(showPass)"
            @click="showPass = !showPass"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <template v-if="!showPass">
                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
                <circle cx="12" cy="12" r="3" />
              </template>
              <template v-else>
                <path d="M3 3l18 18" />
                <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
                <path d="M9.9 5.1A10.9 10.9 0 0 1 12 5c6.5 0 10 7 10 7a16.8 16.8 0 0 1-3.3 4.3" />
                <path d="M6.6 6.6C3.9 8.5 2 12 2 12s3.5 7 10 7a10.4 10.4 0 0 0 4.5-1" />
              </template>
            </svg>
          </button>
        </div>
      </div>

      <div class="auth-field">
        <label :for="`${idPrefix}-r-pass2`"> ยืนยันรหัสผ่านอีกครั้ง <span class="auth-req" aria-hidden="true">*</span> </label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="5" y="10" width="14" height="11" rx="2.5" />
            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
          </svg>
          <input
            :id="`${idPrefix}-r-pass2`"
            ref="pass2Ref"
            v-model="passwordConfirmation"
            :type="showPass ? 'text' : 'password'"
            class="no-trailing-btn"
            required
            minlength="12"
            autocomplete="new-password"
            placeholder="พิมพ์รหัสผ่านอีกครั้ง"
            aria-required="true"
          />
        </div>
      </div>

      <label class="auth-checkbox-row">
        <input v-model="consent" type="checkbox" />
        <span>ยินยอมข้อกำหนดการใช้งานและนโยบายความเป็นส่วนตัวของ POPSTAR Connect</span>
      </label>

      <button type="submit" class="auth-submit" :disabled="busy">
        <svg
          v-if="busy"
          class="auth-spinner"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2.4"
          aria-hidden="true"
        >
          <circle cx="12" cy="12" r="9" stroke-opacity="0.28" />
          <path d="M21 12a9 9 0 0 0-9-9" stroke-linecap="round" />
        </svg>
        <span>{{ busy ? 'กำลังสมัครสมาชิก...' : 'ยืนยันสมัครสมาชิกใหม่' }}</span>
        <svg
          v-if="!busy"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2.1"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M5 12h14m-6-6 6 6-6 6" />
        </svg>
      </button>
    </form>

    <div class="auth-bottom-box is-member">
      <span class="auth-bottom-label">มีบัญชีสมาชิกอยู่แล้ว?</span>
      <button type="button" class="auth-link-btn" @click="$emit('back-to-login')">
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2.1"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M19 12H5m6 6-6-6 6-6" />
        </svg>
        <span>กลับไปหน้าเข้าสู่ระบบ</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  busy: { type: Boolean, default: false },
  errorMessage: { type: String, default: '' },
  idPrefix: { type: String, default: 'reg' },
});

const emit = defineEmits(['submit', 'back-to-login']);

const nameRef = ref(null);
const phoneRef = ref(null);
const passRef = ref(null);
const pass2Ref = ref(null);

const name = ref('');
const phone = ref('');
const email = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const consent = ref(false);
const showPass = ref(false);
const localError = ref('');

const displayError = computed(() => localError.value || props.errorMessage);

function onSubmit() {
  localError.value = '';
  if (!name.value || name.value.length < 2) {
    localError.value = 'กรุณากรอกชื่อร้านค้าหรือชื่อผู้ติดต่ออย่างน้อย 2 ตัวอักษร';
    nameRef.value?.focus();
    return;
  }
  if (!phone.value && !email.value) {
    localError.value = 'กรุณากรอกเบอร์โทรศัพท์หรืออีเมลอย่างน้อย 1 ช่องทาง';
    phoneRef.value?.focus();
    return;
  }
  if (!password.value || password.value.length < 12) {
    localError.value = 'รหัสผ่านต้องมีความยาวอย่างน้อย 12 ตัวอักษร';
    passRef.value?.focus();
    return;
  }
  if (password.value !== passwordConfirmation.value) {
    localError.value = 'รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน';
    pass2Ref.value?.focus();
    return;
  }
  if (!consent.value) {
    localError.value = 'กรุณายอมรับข้อกำหนดและนโยบายความเป็นส่วนตัวก่อนสมัครสมาชิก';
    return;
  }
  emit('submit', {
    name: name.value,
    ...(phone.value ? { phone: phone.value } : {}),
    ...(email.value ? { email: email.value } : {}),
    password: password.value,
    password_confirmation: passwordConfirmation.value,
    consent: consent.value,
  });
}
</script>
