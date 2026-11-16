<template>
  <div>
    <div class="auth-notice">
      สามารถสมัครสมาชิกได้ก่อนมีรหัสลูกค้า ERP — ประวัติยอดซื้อ Bplus และคะแนนสะสมจะปลดล็อกเมื่อทีม Popstar ยืนยันความเป็นเจ้าของร้านค้าแล้ว
    </div>

    <div v-if="displayError" class="auth-error" role="alert" aria-live="assertive">
      {{ displayError }}
    </div>

    <form novalidate @submit.prevent="onSubmit">
      <div class="auth-field">
        <label :for="`${idPrefix}-r-name`">ชื่อร้านค้า / ผู้ติดต่อ <span style="color:#e65f72">*</span></label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="8" r="4" />
            <path d="M4 21v-2a7 7 0 0 1 14 0v2" />
          </svg>
          <input
            :id="`${idPrefix}-r-name`"
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
        <label :for="`${idPrefix}-r-phone`">เบอร์โทรศัพท์ (อย่างน้อย 1 ช่องทางร่วมกับอีเมล)</label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07A19.5 19.5 0 0 1 5.15 12.8 19.8 19.8 0 0 1 2.08 4.11 2 2 0 0 1 4.06 2h3a2 2 0 0 1 2 1.72c.12.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.58 2.81.7A2 2 0 0 1 22 16.92Z" />
          </svg>
          <input
            :id="`${idPrefix}-r-phone`"
            v-model.trim="phone"
            class="no-trailing-btn"
            type="tel"
            inputmode="tel"
            placeholder="08x-xxx-xxxx"
          />
        </div>
      </div>

      <div class="auth-field">
        <label :for="`${idPrefix}-r-email`">อีเมล (ไม่บังคับหากกรอกเบอร์โทร)</label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="m3 7 9 6 9-6" />
          </svg>
          <input
            :id="`${idPrefix}-r-email`"
            v-model.trim="email"
            class="no-trailing-btn"
            type="email"
            placeholder="shop@example.com"
          />
        </div>
      </div>

      <div class="auth-field">
        <label :for="`${idPrefix}-r-pass`">รหัสผ่าน (อย่างน้อย 12 ตัวอักษร) <span style="color:#e65f72">*</span></label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="5" y="10" width="14" height="11" rx="2" />
            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
          </svg>
          <input
            :id="`${idPrefix}-r-pass`"
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
            :aria-label="showPass ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'"
            :aria-pressed="String(showPass)"
            @click="showPass = !showPass"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
              <circle cx="12" cy="12" r="3" />
            </svg>
          </button>
        </div>
      </div>

      <div class="auth-field">
        <label :for="`${idPrefix}-r-pass2`">ยืนยันรหัสผ่าน <span style="color:#e65f72">*</span></label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="5" y="10" width="14" height="11" rx="2" />
            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
          </svg>
          <input
            :id="`${idPrefix}-r-pass2`"
            v-model="passwordConfirmation"
            :type="showPass ? 'text' : 'password'"
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
        <span>ยินยอมข้อกำหนดและนโยบายความเป็นส่วนตัวของ Popstar Loyalty &amp; Portal</span>
      </label>

      <button type="submit" class="auth-submit" :disabled="busy">
        <span>{{ busy ? 'กำลังสมัครสมาชิก...' : 'สมัครสมาชิกใหม่' }}</span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M5 12h14m-6-6 6 6-6 6" />
        </svg>
      </button>
    </form>

    <p class="auth-footnote">
      มีบัญชีสมาชิกอยู่แล้ว?
      <button type="button" class="auth-link" @click="$emit('back-to-login')">
        ← กลับไปหน้าเข้าสู่ระบบ
      </button>
    </p>
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
    localError.value = 'กรุณากรอกชื่อร้านค้าหรือผู้ติดต่ออย่างน้อย 2 ตัวอักษร';
    return;
  }
  if (!phone.value && !email.value) {
    localError.value = 'กรุณากรอกเบอร์โทรศัพท์หรืออีเมลอย่างน้อย 1 ช่องทาง';
    return;
  }
  if (!password.value || password.value.length < 12) {
    localError.value = 'รหัสผ่านต้องมีความยาวอย่างน้อย 12 ตัวอักษร';
    return;
  }
  if (password.value !== passwordConfirmation.value) {
    localError.value = 'รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน';
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
