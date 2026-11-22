<template>
  <AuthLayout :title="headerCopy.title" :subtitle="headerCopy.subtitle" :loading="loading">
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
      title: 'กำลังตรวจสอบสิทธิ์',
      subtitle: 'กำลังตรวจสอบการเข้าสู่ระบบของคุณ...',
    };
  }
  if (showRegister.value) {
    return {
      title: 'สมัครสมาชิกใหม่',
      subtitle: 'สมัครบัญชีร้านค้าเพื่อรับสิทธิประโยชน์และดูคะแนนสะสม',
    };
  }
  if (authRole.value === 'member') {
    return {
      title: 'เข้าสู่ระบบสมาชิก',
      subtitle: 'ดูคะแนนสะสม ประวัติซื้อ และสิทธิประโยชน์ของคุณ',
    };
  }
  return {
    title: 'เข้าสู่ระบบพนักงาน',
    subtitle: 'เข้าถึงพื้นที่ทำงานและจัดการงานขายของคุณ',
  };
});

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
    errorMessage.value = err?.message || 'เข้าสู่ระบบไม่สำเร็จ';
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
    errorMessage.value = err?.message || 'สมัครสมาชิกไม่สำเร็จ';
    busy.value = false;
  }
}
</script>
