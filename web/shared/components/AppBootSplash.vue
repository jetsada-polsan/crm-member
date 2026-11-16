<template>
  <div class="boot-splash" role="status" aria-live="polite">
    <div class="boot-mark" aria-hidden="true">P</div>
    <div class="boot-spinner" aria-hidden="true"></div>
    <p class="boot-text">{{ message }}</p>
  </div>
</template>

<script setup>
/**
 * Neutral full-screen loader shown while the portal checks an existing session,
 * so a page refresh never flashes the login form for already signed-in users.
 */
defineProps({
  message: { type: String, default: 'กำลังโหลด…' },
});
</script>

<style scoped>
.boot-splash {
  position: fixed;
  inset: 0;
  display: grid;
  place-content: center;
  justify-items: center;
  gap: 16px;
  background: #f4f6fb;
  color: #5b6577;
}
.boot-mark {
  width: 56px;
  height: 56px;
  border-radius: 16px;
  display: grid;
  place-items: center;
  font-size: 26px;
  font-weight: 800;
  color: #fff;
  background: linear-gradient(135deg, #e11d48, #f97316);
}
.boot-spinner {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 3px solid #dfe5ef;
  border-top-color: #e11d48;
  animation: bootSpin 0.8s linear infinite;
}
.boot-text {
  margin: 0;
  font-size: 14px;
}
@keyframes bootSpin {
  to {
    transform: rotate(360deg);
  }
}
</style>
