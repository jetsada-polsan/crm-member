<template>
  <div class="auth-switcher" role="tablist" aria-label="เลือกประเภทบัญชีผู้ใช้งาน" @keydown="onKeydown">
    <span :class="['auth-switcher-pill', { right: modelValue === 'member' }]" aria-hidden="true"></span>
    <button
      id="role-tab-staff"
      ref="staffBtnRef"
      type="button"
      role="tab"
      :tabindex="modelValue === 'staff' ? 0 : -1"
      :aria-selected="String(modelValue === 'staff')"
      :class="{ active: modelValue === 'staff' }"
      @click="selectRole('staff')"
    >
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.9"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <rect x="3" y="7" width="18" height="14" rx="2.5" />
        <path d="M8 7V5a2.5 2.5 0 0 1 2.5-2.5h3A2.5 2.5 0 0 1 16 5v2" />
      </svg>
      <span>พนักงาน</span>
    </button>

    <button
      id="role-tab-member"
      ref="memberBtnRef"
      type="button"
      role="tab"
      :tabindex="modelValue === 'member' ? 0 : -1"
      :aria-selected="String(modelValue === 'member')"
      :class="{ active: modelValue === 'member' }"
      @click="selectRole('member')"
    >
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.9"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <circle cx="12" cy="8" r="4" />
        <path d="M4 21v-1.5A6.5 6.5 0 0 1 10.5 13h3A6.5 6.5 0 0 1 20 19.5V21" />
      </svg>
      <span>สมาชิก</span>
    </button>
  </div>
</template>

<script setup>
import { nextTick, ref } from 'vue';

const props = defineProps({
  modelValue: {
    type: String,
    required: true,
    validator: (v) => ['staff', 'member'].includes(v),
  },
});

const emit = defineEmits(['update:modelValue']);

const staffBtnRef = ref(null);
const memberBtnRef = ref(null);

function selectRole(role, focusButton = false) {
  if (role !== props.modelValue) {
    emit('update:modelValue', role);
  }
  if (focusButton) {
    nextTick(() => {
      const target = role === 'staff' ? staffBtnRef.value : memberBtnRef.value;
      target?.focus();
    });
  }
}

function onKeydown(e) {
  if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
    e.preventDefault();
    selectRole(props.modelValue === 'staff' ? 'member' : 'staff', true);
  } else if (e.key === 'Home') {
    e.preventDefault();
    selectRole('staff', true);
  } else if (e.key === 'End') {
    e.preventDefault();
    selectRole('member', true);
  }
}
</script>
