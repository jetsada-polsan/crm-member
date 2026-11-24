<template>
  <main class="fpc">
    <form class="fpc-card card" autocomplete="on" @submit.prevent="submit">
      <div class="fpc-icon" aria-hidden="true">🔐</div>
      <h1>ตั้งรหัสผ่านใหม่ก่อนใช้งาน</h1>
      <p class="muted">
        สวัสดี {{ me?.name || '' }} — บัญชีนี้ยังใช้รหัสชั่วคราวหรือรหัสเดิมที่ไม่ปลอดภัย กรุณาตั้งรหัสผ่านของคุณเอง (ไม่มีใครรู้นอกจากคุณ)
      </p>

      <div class="field">
        <label for="fpc-current">รหัสผ่านปัจจุบัน (ที่ใช้เข้าสู่ระบบเมื่อกี้)</label>
        <input id="fpc-current" v-model="currentPassword" type="password" autocomplete="current-password" required />
      </div>
      <div class="field">
        <label for="fpc-new">รหัสผ่านใหม่ (อย่างน้อย 10 ตัวอักษร)</label>
        <input id="fpc-new" v-model="newPassword" type="password" autocomplete="new-password" minlength="10" required />
      </div>
      <div class="field">
        <label for="fpc-confirm">พิมพ์รหัสผ่านใหม่อีกครั้ง</label>
        <input
          id="fpc-confirm"
          v-model="confirmPassword"
          type="password"
          autocomplete="new-password"
          minlength="10"
          required
        />
      </div>

      <div v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</div>

      <button type="submit" class="btn primary fpc-submit" :disabled="saving || !canSubmit">
        {{ saving ? 'กำลังบันทึก…' : 'บันทึกรหัสผ่านใหม่' }}
      </button>
      <button type="button" class="btn fpc-logout" :disabled="saving" @click="$emit('logout')">ออกจากระบบ</button>
    </form>
  </main>
</template>

<script setup>
import { computed, ref } from 'vue';
import { clearCsrfCache } from '../../../shared/api/http.js';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmMutate, me, refreshSession } from '../composables/useCrmSession.js';

defineEmits(['logout']);

const currentPassword = ref('');
const newPassword = ref('');
const confirmPassword = ref('');
const saving = ref(false);
const errorMessage = ref('');

const canSubmit = computed(
  () => currentPassword.value.length > 0 && newPassword.value.length >= 10 && confirmPassword.value.length >= 10
);

function firstValidationMessage(err) {
  const errors = err?.data?.errors || err?.errors || err?.response?.errors;
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flat()[0];
    if (first) return String(first);
  }
  return err?.message || 'บันทึกรหัสผ่านไม่สำเร็จ';
}

async function submit() {
  errorMessage.value = '';
  if (newPassword.value !== confirmPassword.value) {
    errorMessage.value = 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน';
    return;
  }
  saving.value = true;
  try {
    await crmMutate('/me/password', 'POST', {
      current_password: currentPassword.value,
      password: newPassword.value,
      password_confirmation: confirmPassword.value,
    });
    currentPassword.value = '';
    newPassword.value = '';
    confirmPassword.value = '';
    // The server rotates the session after a password change, so the cached CSRF token is stale.
    clearCsrfCache();
    await refreshSession();
    toast.success('ตั้งรหัสผ่านใหม่เรียบร้อย', 'ครั้งหน้าใช้รหัสผ่านนี้เข้าสู่ระบบ');
  } catch (err) {
    errorMessage.value = firstValidationMessage(err);
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.fpc {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 24px 16px calc(24px + env(safe-area-inset-bottom, 0px));
  background: var(--bg);
}
.fpc-card {
  width: min(440px, 100%);
  padding: 26px 22px;
  display: grid;
  gap: 14px;
}
.fpc-icon {
  font-size: 34px;
  line-height: 1;
}
.fpc-card h1 {
  margin: 0;
  font-size: 21px;
}
.fpc-card p {
  margin: 0;
  font-size: 14.5px;
  line-height: 1.6;
}
.fpc-card .error {
  margin-bottom: 0;
}
.fpc-submit,
.fpc-logout {
  width: 100%;
}
</style>
