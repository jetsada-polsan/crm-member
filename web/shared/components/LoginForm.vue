<template>
  <div class="auth-form-container">
    <div v-if="displayError" class="auth-error" role="alert" aria-live="assertive">
      <svg class="auth-error-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path d="M12 8v4m0 4h.01" />
      </svg>
      <span>{{ displayError }}</span>
    </div>

    <form class="auth-form" novalidate @submit.prevent="onSubmit">
      <!-- 5. ช่อง Username / Email -->
      <div class="auth-field">
        <label :for="identId">
          {{ roleMode === 'staff' ? 'ชื่อผู้ใช้ / รหัสพนักงาน / อีเมล' : 'เบอร์โทรศัพท์ หรือ อีเมล' }}
        </label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <template v-if="roleMode === 'staff'">
              <circle cx="12" cy="8" r="4" />
              <path d="M4 20v-1a7 7 0 0 1 14 0v1" />
            </template>
            <template v-else>
              <rect x="3" y="5" width="18" height="14" rx="2.5" />
              <path d="m3 7 9 6 9-6" />
            </template>
          </svg>
          <input
            :id="identId"
            ref="identRef"
            v-model.trim="identifier"
            type="text"
            inputmode="text"
            class="no-trailing-btn"
            autocomplete="username"
            :placeholder="
              roleMode === 'staff'
                ? 'เช่น B16, 01 หรืออีเมลพนักงาน'
                : '08x-xxx-xxxx หรือ email@domain.com'
            "
            aria-required="true"
            required
          />
        </div>
      </div>

      <!-- 6. ช่อง Password & ปุ่มแสดง/ซ่อนรหัสผ่าน -->
      <div class="auth-field">
        <label :for="passId">รหัสผ่าน</label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="5" y="10" width="14" height="11" rx="2.5" />
            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
          </svg>
          <input
            :id="passId"
            ref="passRef"
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
            placeholder="กรอกรหัสผ่านของคุณ"
            aria-required="true"
            required
          />
          <button
            type="button"
            class="auth-eye"
            :title="showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'"
            :aria-label="showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'"
            :aria-pressed="String(showPassword)"
            @click="showPassword = !showPassword"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <template v-if="!showPassword">
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

      <!-- 7. Remember Me & คำแนะนำกรณีลืมรหัสผ่าน -->
      <div class="auth-tools">
        <label class="auth-remember">
          <input v-model="remember" type="checkbox" />
          <span>จำชื่อผู้ใช้งาน</span>
        </label>
        <button
          type="button"
          class="auth-help-trigger"
          :aria-expanded="String(showForgotHelp)"
          @click="showForgotHelp = !showForgotHelp"
        >
          {{ showForgotHelp ? 'ซ่อนคำแนะนำ' : 'ลืมรหัสผ่าน?' }}
        </button>
      </div>

      <div v-if="showForgotHelp" class="auth-help-box" role="region" aria-label="คำแนะนำการรีเซ็ตรหัสผ่าน">
        <svg class="auth-help-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
          <circle cx="12" cy="12" r="9" />
          <path d="M12 8h.01M11 12h1v4h1" />
        </svg>
        <div class="auth-help-content">
          <template v-if="roleMode === 'staff'">
            <strong>การตั้งรหัสผ่านใหม่สำหรับพนักงาน</strong>
            <span>กรุณาติดต่อผู้ดูแลระบบ (Admin) หรือหัวหน้าทีมขาย เพื่อตรวจสอบและรีเซ็ตรหัสผ่านให้บัญชีของคุณ</span>
          </template>
          <template v-else>
            <strong>การตั้งรหัสผ่านใหม่สำหรับสมาชิก</strong>
            <span>กรุณาติดต่อผู้ดูแลระบบผ่านช่องทางบริการลูกค้าของ POPSTAR หรือแจ้งพนักงานขายที่ดูแลร้านค้าของท่าน</span>
          </template>
        </div>
      </div>

      <!-- 8. ปุ่มเข้าสู่ระบบ -->
      <button class="auth-submit" type="submit" :disabled="busy">
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
        <span>
          {{
            busy
              ? 'กำลังตรวจสอบสิทธิ์...'
              : roleMode === 'staff'
                ? 'เข้าสู่ระบบพนักงาน'
                : 'เข้าสู่ระบบสมาชิก'
          }}
        </span>
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

    <!-- 9. ลิงก์สมัครสมาชิก / ติดต่อผู้ดูแลระบบ -->
    <div :class="['auth-bottom-box', roleMode === 'member' ? 'is-member' : 'is-staff']">
      <template v-if="roleMode === 'member'">
        <span class="auth-bottom-label">ยังไม่มีบัญชีสมาชิกร้านค้า?</span>
        <button type="button" class="auth-link-btn" @click="$emit('open-register')">
          <span>สมัครสมาชิกใหม่</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 12h14m-6-6 6 6-6 6" />
          </svg>
        </button>
      </template>
      <template v-else>
        <svg class="auth-bottom-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        </svg>
        <div class="auth-bottom-text">
          <strong>สำหรับพนักงาน POPSTAR</strong>
          <span>ต้องการเปิดบัญชีผู้ใช้งานหรือพบปัญหาเข้าสู่ระบบ กรุณาติดต่อผู้ดูแลระบบ</span>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { readRememberedIdentifier } from '../composables/usePortalRedirect.js';

const props = defineProps({
  roleMode: { type: String, required: true },
  busy: { type: Boolean, default: false },
  errorMessage: { type: String, default: '' },
  idPrefix: { type: String, default: 'auth' },
});

const emit = defineEmits(['submit', 'open-register']);

const identId = computed(() => `${props.idPrefix}-ident`);
const passId = computed(() => `${props.idPrefix}-pass`);

const identRef = ref(null);
const passRef = ref(null);

const identifier = ref(readRememberedIdentifier(props.roleMode));
const password = ref('');
const remember = ref(Boolean(identifier.value));
const showPassword = ref(false);
const showForgotHelp = ref(false);
const localError = ref('');

const displayError = computed(() => localError.value || props.errorMessage);

watch(
  () => props.roleMode,
  (nextRole) => {
    localError.value = '';
    password.value = '';
    showPassword.value = false;
    showForgotHelp.value = false;
    const saved = readRememberedIdentifier(nextRole);
    identifier.value = saved;
    remember.value = Boolean(saved);
  }
);

function onSubmit() {
  localError.value = '';
  if (!identifier.value) {
    localError.value =
      props.roleMode === 'staff'
        ? 'กรุณากรอกชื่อผู้ใช้ รหัสพนักงาน หรืออีเมลก่อนเข้าสู่ระบบ'
        : 'กรุณากรอกเบอร์โทรศัพท์หรืออีเมลสมาชิกก่อนเข้าสู่ระบบ';
    identRef.value?.focus();
    return;
  }
  if (!password.value) {
    localError.value = 'กรุณากรอกรหัสผ่าน';
    passRef.value?.focus();
    return;
  }
  emit('submit', {
    roleMode: props.roleMode,
    identifier: identifier.value,
    password: password.value,
    remember: remember.value,
  });
}
</script>
