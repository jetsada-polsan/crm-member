<template>
  <div>
    <div :class="['auth-role-banner', roleMode === 'member' ? 'is-member' : 'is-staff']">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <template v-if="roleMode === 'member'">
          <path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4Z" />
          <path d="m9 12 2 2 4-4" />
        </template>
        <template v-else>
          <rect x="3" y="7" width="18" height="14" rx="2" />
          <path d="M8 7V5a3 3 0 0 1 3-3h2a3 3 0 0 1 3 3v2" />
        </template>
      </svg>
      <div class="auth-role-banner-text">
        <template v-if="roleMode === 'member'">
          <strong>บัญชีสมาชิกของคุณ</strong><br />
          ดูประวัติซื้อ คะแนนสะสม และสิทธิพิเศษ เมื่อยืนยันและผูกบัญชี ERP แล้ว
        </template>
        <template v-else>
          <strong>พื้นที่ทำงานพนักงาน &amp; ผู้ดูแล</strong><br />
          บันทึกงานภาคสนาม ติดตาม Lead และตรวจสอบ ERP Sync Mirror อย่างปลอดภัย
        </template>
      </div>
    </div>

    <div v-if="displayError" class="auth-error" role="alert" aria-live="assertive">
      {{ displayError }}
    </div>

    <form novalidate @submit.prevent="onSubmit">
      <div class="auth-field">
        <label :for="identId">
          {{ roleMode === 'staff' ? 'อีเมลพนักงาน / CRM' : 'เบอร์โทรศัพท์หรืออีเมลสมาชิก' }}
        </label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="m3 7 9 6 9-6" />
          </svg>
          <input
            :id="identId"
            ref="identRef"
            v-model.trim="identifier"
            :type="roleMode === 'staff' ? 'email' : 'text'"
            :inputmode="roleMode === 'staff' ? 'email' : 'text'"
            class="no-trailing-btn"
            autocomplete="username"
            :placeholder="roleMode === 'staff' ? 'กรอกอีเมลพนักงาน (เช่น name@popstar.local)' : '08x-xxx-xxxx หรือ email@domain.com'"
            aria-required="true"
            required
          />
        </div>
      </div>

      <div class="auth-field">
        <label :for="passId">รหัสผ่าน</label>
        <div class="auth-input-wrap">
          <svg class="auth-input-icon" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="5" y="10" width="14" height="11" rx="2" />
            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
          </svg>
          <input
            :id="passId"
            ref="passRef"
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
            placeholder="กรอกรหัสผ่าน"
            aria-required="true"
            required
          />
          <button
            type="button"
            class="auth-eye"
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

      <div class="auth-tools">
        <label class="auth-remember">
          <input v-model="remember" type="checkbox" />
          <span>จำชื่อผู้ใช้</span>
        </label>
        <button
          type="button"
          class="auth-link-disabled"
          disabled
          aria-disabled="true"
          title="ยังไม่เปิดใช้งานการรีเซ็ตรหัสผ่านอัตโนมัติ กรุณาติดต่อผู้ดูแลระบบ"
        >
          ลืมรหัสผ่าน? (ยังไม่เปิดใช้งาน)
        </button>
      </div>

      <button class="auth-submit" type="submit" :disabled="busy">
        <span>
          {{
            busy
              ? 'กำลังตรวจสอบสิทธิ์...'
              : roleMode === 'staff'
                ? 'เข้าสู่ระบบ Workspace'
                : 'เข้าสู่ระบบสมาชิก'
          }}
        </span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M5 12h14m-6-6 6 6-6 6" />
        </svg>
      </button>
    </form>

    <div class="auth-divider">หรือช่องทางอื่น</div>

    <button
      type="button"
      class="auth-alt"
      disabled
      aria-disabled="true"
      :title="roleMode === 'staff' ? 'บัญชีพนักงานเปิดโดยผู้ดูแลระบบเท่านั้น' : 'LINE Login ยังไม่เปิดใช้งาน กรุณาเข้าสู่ระบบแล้วผูกบัญชี LINE ภายในพอร์ทัล'"
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path d="M3 6h18v12H3z" />
        <path d="m3 7 9 7 9-7" />
      </svg>
      <span>
        {{
          roleMode === 'staff'
            ? 'ติดต่อผู้ดูแลระบบ IT เพื่อขอสิทธิ์ใช้งาน'
            : 'เข้าสู่ระบบด้วย LINE (ยังไม่เปิดใช้งาน)'
        }}
      </span>
    </button>

    <p class="auth-footnote">
      <template v-if="roleMode === 'staff'">
        ต้องการเปิดบัญชี CRM หรือพบปัญหาใช้งาน? ติดต่อผู้ดูแลระบบ IT
      </template>
      <template v-else>
        ยังไม่มีบัญชีสมาชิก?
        <button type="button" class="auth-link" @click="$emit('open-register')">
          สมัครสมาชิกใหม่ →
        </button>
      </template>
      <br />POPSTAR Connect · ทีมขาย &amp; สมาชิก
    </p>
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
const localError = ref('');

const displayError = computed(() => localError.value || props.errorMessage);

watch(
  () => props.roleMode,
  (nextRole) => {
    localError.value = '';
    password.value = '';
    showPassword.value = false;
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
        ? 'กรุณากรอกอีเมลพนักงานก่อนเข้าสู่ระบบ'
        : 'กรุณากรอกเบอร์โทรศัพท์หรืออีเมลสมาชิก';
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
