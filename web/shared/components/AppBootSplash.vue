<template>
  <div class="boot-splash" role="status" aria-live="polite">
    <div class="boot-mark" aria-hidden="true">
      <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="boot-bg" x1="0" y1="0" x2="1" y2="1">
            <stop stop-color="#19b7e8" />
            <stop offset="1" stop-color="#0284c7" />
          </linearGradient>
        </defs>
        <rect x="2" y="2" width="60" height="60" rx="16" fill="url(#boot-bg)" />
        <path
          fill="#fff"
          d="M18 15h16c9 0 14 4.4 14 12.3S43 40 34 40h-6v10H18zm10 8v9h5.4c3.4 0 5.3-1.6 5.3-4.5S36.8 23 33.4 23z"
        />
        <path fill="#ffd45a" d="m49 9 1.5 3.1 3.5.5-2.5 2.4.6 3.4-3.1-1.6-3.1 1.6.6-3.4-2.5-2.4 3.5-.5z" />
      </svg>
    </div>
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
  gap: 14px;
  background: #f8fafc;
  color: #475569;
}
.boot-mark {
  width: 54px;
  height: 54px;
  border-radius: 15px;
  display: grid;
  place-items: center;
  box-shadow: 0 8px 20px rgba(2, 132, 199, 0.2);
}
.boot-mark svg {
  width: 54px;
  height: 54px;
  display: block;
}
.boot-spinner {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  border: 2.5px solid #e2e8f0;
  border-top-color: #0284c7;
  animation: bootSpin 0.75s linear infinite;
}
.boot-text {
  margin: 0;
  font-size: 14px;
  font-weight: 500;
}
@keyframes bootSpin {
  to {
    transform: rotate(360deg);
  }
}
</style>
