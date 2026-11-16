<template>
  <div class="auth-switcher" role="tablist" aria-label="ประเภทการเข้าสู่ระบบ" @keydown="onKeydown">
    <span
      :class="['auth-switcher-pill', { right: modelValue === 'member' }]"
      aria-hidden="true"
    ></span>
    <button
      id="role-tab-staff"
      type="button"
      role="tab"
      :tabindex="modelValue === 'staff' ? 0 : -1"
      :aria-selected="String(modelValue === 'staff')"
      :class="{ active: modelValue === 'staff' }"
      @click="selectRole('staff')"
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <rect x="3" y="7" width="18" height="14" rx="2" />
        <path d="M8 7V5a3 3 0 0 1 3-3h2a3 3 0 0 1 3 3v2" />
      </svg>
      พนักงาน / CRM
    </button>

    <button
      id="role-tab-member"
      type="button"
      role="tab"
      :tabindex="modelValue === 'member' ? 0 : -1"
      :aria-selected="String(modelValue === 'member')"
      :class="{ active: modelValue === 'member' }"
      @click="selectRole('member')"
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <circle cx="12" cy="7" r="4" />
        <path d="M4 22v-2a8 8 0 0 1 16 0v2" />
      </svg>
      ลูกค้า / สมาชิก
    </button>
  </div>
</template>

<script setup>
const props = defineProps({
  modelValue: {
    type: String,
    required: true,
    validator: (v) => ['staff', 'member'].includes(v),
  },
});

const emit = defineEmits(['update:modelValue']);

function selectRole(role) {
  if (role !== props.modelValue) {
    emit('update:modelValue', role);
  }
}

function onKeydown(e) {
  if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
    e.preventDefault();
    selectRole(props.modelValue === 'staff' ? 'member' : 'staff');
  } else if (e.key === 'Home') {
    e.preventDefault();
    selectRole('staff');
  } else if (e.key === 'End') {
    e.preventDefault();
    selectRole('member');
  }
}
</script>
