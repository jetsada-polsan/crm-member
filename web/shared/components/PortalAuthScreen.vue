<template>
  <AuthLayout
    :welcome-text="headerCopy.welcome"
    :title="headerCopy.title"
    :subtitle="headerCopy.subtitle"
    :loading="loading"
  >
    <LoginRoleSwitcher v-if="!showRegister" v-model="authRole" />

    <LoginForm
      v-if="!showRegister"
      :id-prefix="idPrefix"
      :role-mode="authRole"
      :busy="busy"
      :error-message="errorMessage"
      @submit="handleLogin"
      @open-register="openRegister"
    />

    <MemberRegisterForm
      v-else
      :id-prefix="`${idPrefix}-reg`"
      :busy="busy"
      :error-message="errorMessage"
      @submit="handleRegister"
      @back-to-login="backToLogin"
    />
  </AuthLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AuthLayout from './AuthLayout.vue';
import LoginRoleSwitcher from './LoginRoleSwitcher.vue';
import LoginForm from './LoginForm.vue';
import MemberRegisterForm from './MemberRegisterForm.vue';
import { saveRememberedIdentifier } from '../composables/usePortalRedirect.js';

const props = defineProps({
  defaultRole: {
    type: String,
    required: true,
    validator: (v) => ['staff', 'member'].includes(v),
  },
  idPrefix: { type: String, required: true },
  loading: { type: Boolean, default: false },
  submitLogin: { type: Function, required: true },
  submitRegister: { type: Function, required: true },
});

const authRole = ref(props.defaultRole);
const showRegister = ref(false);
const busy = ref(false);
const errorMessage = ref('');

const headerCopy = computed(() => {
  if (props.loading) {
    return {
      welcome: 'POPSTAR Connect',
      title: 'กำลังตรวจสอบสิทธิ์',
      subtitle: 'กรุณารอสักครู่ ระบบกำลังตรวจสอบสถานะเซสชันของคุณ...',
    };
  }
  if (showRegister.value) {
    return {
      welcome: 'ยินดีต้อนรับสู่ POPSTAR Connect',
      title: 'สมัครสมาชิกใหม่',
      subtitle: 'ลงทะเบียนบัญชีร้านค้าเพื่อตรวจสอบยอดซื้อและสะสมคะแนนแลกของรางวัล',
    };
  }
  if (authRole.value === 'member') {
    return {
      welcome: 'ยินดีต้อนรับสู่ POPSTAR Connect',
      title: 'เข้าสู่ระบบสมาชิก',
      subtitle: 'ตรวจสอบคะแนนสะสม ประวัติยอดซื้อ และสิทธิพิเศษสำหรับร้านค้าของคุณ',
    };
  }
  return {
    welcome: 'ยินดีต้อนรับสู่ POPSTAR Connect',
    title: 'เข้าสู่ระบบพนักงาน',
    subtitle: 'สำหรับทีมขายภาคสนาม ผู้จัดการ และผู้ดูแลระบบ',
  };
});

function formatAuthError(err, fallback) {
  const raw = String(err?.message || '').trim();
  if (!raw) return fallback;
  if (raw === 'Invalid credentials') {
    return 'ข้อมูลผู้ใช้หรือรหัสผ่านไม่ถูกต้อง กรุณาตรวจสอบและลองใหม่อีกครั้ง';
  }
  if (raw === 'Disabled account') {
    return 'บัญชีนี้ถูกระงับการใช้งานชั่วคราว กรุณาติดต่อผู้ดูแลระบบ';
  }
  if (raw === 'This phone number is already registered.') {
    return 'เบอร์โทรศัพท์นี้ถูกใช้สมัครสมาชิกแล้ว กรุณาเข้าสู่ระบบหรือใช้เบอร์อื่น';
  }
  if (raw === 'Enter a valid phone number.') {
    return 'กรุณากรอกเบอร์โทรศัพท์ให้ถูกต้อง (ตัวเลข 8–15 หลัก)';
  }
  if (raw.toLowerCase().includes('email has already been taken')) {
    return 'อีเมลนี้ถูกใช้งานแล้ว กรุณาใช้อีเมลอื่นหรือเข้าสู่ระบบ';
  }
  return raw;
}

watch(authRole, () => {
  errorMessage.value = '';
  showRegister.value = false;
});

function openRegister() {
  errorMessage.value = '';
  showRegister.value = true;
}

function backToLogin() {
  errorMessage.value = '';
  showRegister.value = false;
}

async function handleLogin(payload) {
  saveRememberedIdentifier(payload.roleMode, payload.identifier, payload.remember);
  busy.value = true;
  errorMessage.value = '';
  try {
    const result = await props.submitLogin(payload);
    if (!result?.redirected) {
      busy.value = false;
    }
  } catch (err) {
    errorMessage.value = formatAuthError(err, 'เข้าสู่ระบบไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    busy.value = false;
  }
}

async function handleRegister(payload) {
  busy.value = true;
  errorMessage.value = '';
  try {
    const result = await props.submitRegister(payload);
    if (!result?.redirected) {
      busy.value = false;
    }
  } catch (err) {
    errorMessage.value = formatAuthError(err, 'สมัครสมาชิกไม่สำเร็จ กรุณาตรวจสอบข้อมูลอีกครั้ง');
    busy.value = false;
  }
}
</script>
