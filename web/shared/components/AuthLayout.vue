<template>
  <main class="auth-shell">
    <!-- Desktop Left Panel: Branding & Introduction (Hidden on Mobile/Tablet) -->
    <aside class="auth-brand-panel" aria-label="ข้อมูลระบบ POPSTAR Connect">
      <div class="auth-brand-decor" aria-hidden="true">
        <div class="auth-brand-glow auth-brand-glow-top"></div>
        <div class="auth-brand-glow auth-brand-glow-bottom"></div>
      </div>

      <header class="auth-brand-header">
        <div class="auth-brand-logo">
          <div class="auth-logo-mark" aria-hidden="true">
            <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
              <defs>
                <linearGradient id="popstar-brand-grad" x1="0" y1="0" x2="1" y2="1">
                  <stop stop-color="#38bdf8" />
                  <stop offset="1" stop-color="#0284c7" />
                </linearGradient>
              </defs>
              <rect x="2" y="2" width="60" height="60" rx="16" fill="url(#popstar-brand-grad)" />
              <path
                fill="#fff"
                d="M18 15h16c9 0 14 4.4 14 12.3S43 40 34 40h-6v10H18zm10 8v9h5.4c3.4 0 5.3-1.6 5.3-4.5S36.8 23 33.4 23z"
              />
              <path
                fill="#ffd45a"
                d="m49 9 1.5 3.1 3.5.5-2.5 2.4.6 3.4-3.1-1.6-3.1 1.6.6-3.4-2.5-2.4 3.5-.5z"
              />
            </svg>
          </div>
          <div class="auth-brand-meta">
            <strong>POPSTAR <span>Connect</span></strong>
            <small>ระบบบริหารงานขาย &amp; สมาชิกร้านค้า</small>
          </div>
        </div>
        <span class="auth-status-pill">
          <span class="auth-status-dot" aria-hidden="true"></span>
          <span>ระบบพร้อมใช้งาน</span>
        </span>
      </header>

      <div class="auth-brand-body">
        <div class="auth-brand-eyebrow">POPSTAR BUSINESS PLATFORM</div>
        <h1 class="auth-brand-title">
          ยกระดับการดูแลร้านค้า<br />
          <span>และงานขายภาคสนามครบวงจร</span>
        </h1>
        <p class="auth-brand-desc">
          เชื่อมโยงข้อมูลการทำงานของทีมขาย ผู้จัดการ และสมาชิกร้านค้าไว้ในระบบเดียว ใช้งานง่าย รวดเร็ว และรองรับการทำงานจากทุกอุปกรณ์
        </p>

        <ul class="auth-highlights" aria-label="จุดเด่นของระบบ 3 รายการ">
          <li class="auth-highlight-card">
            <div class="auth-highlight-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 21s-7-5.6-7-11a7 7 0 1 1 14 0c0 5.4-7 11-7 11Z" />
                <circle cx="12" cy="10" r="2.5" />
              </svg>
            </div>
            <div class="auth-highlight-text">
              <strong>บันทึกงานขายและออกตลาดได้ทันที</strong>
              <span>เช็คอินร้านค้า ถ่ายรูปหน้างาน และติดตามแผนงานขายประจำวันผ่านมือถือ</span>
            </div>
          </li>

          <li class="auth-highlight-card">
            <div class="auth-highlight-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 3v18h18" />
                <path d="m7 15 4-4 3 3 5-6" />
              </svg>
            </div>
            <div class="auth-highlight-text">
              <strong>ข้อมูลลูกค้าและยอดขายแม่นยำ</strong>
              <span>ตรวจสอบข้อมูลร้านค้า ประวัติการสั่งซื้อ และสถานะลูกค้าที่เชื่อมโยงกับระบบ ERP</span>
            </div>
          </li>

          <li class="auth-highlight-card">
            <div class="auth-highlight-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 16.8 6.2 19.9l1.1-6.5L2.6 8.8l6.5-.9L12 2z" />
              </svg>
            </div>
            <div class="auth-highlight-text">
              <strong>บริการสมาชิกและสะสมคะแนนร้านค้า</strong>
              <span>ร้านค้าสมาชิกตรวจสอบแต้มสะสม ดูประวัติบิลซื้อ และแลกของรางวัลได้สะดวก</span>
            </div>
          </li>
        </ul>
      </div>

      <footer class="auth-brand-footer">
        <span>© 2026 POPSTAR Connect</span>
        <span>ปลอดภัยด้วยการแยกสิทธิ์ผู้ใช้งานมาตรฐานองค์กร</span>
      </footer>
    </aside>

    <!-- Right Panel (Desktop) / Primary Screen (Mobile First) -->
    <section class="auth-form-panel" aria-label="ส่วนเข้าสู่ระบบ">
      <div class="auth-card">
        <!-- 1. โลโก้ POPSTAR Connect ด้านบน (Mobile & Tablet) -->
        <div class="auth-mobile-brand">
          <div class="auth-logo-mark" aria-hidden="true">
            <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
              <defs>
                <linearGradient id="popstar-mob-grad" x1="0" y1="0" x2="1" y2="1">
                  <stop stop-color="#19b7e8" />
                  <stop offset="1" stop-color="#0284c7" />
                </linearGradient>
              </defs>
              <rect x="2" y="2" width="60" height="60" rx="16" fill="url(#popstar-mob-grad)" />
              <path
                fill="#fff"
                d="M18 15h16c9 0 14 4.4 14 12.3S43 40 34 40h-6v10H18zm10 8v9h5.4c3.4 0 5.3-1.6 5.3-4.5S36.8 23 33.4 23z"
              />
              <path
                fill="#ffd45a"
                d="m49 9 1.5 3.1 3.5.5-2.5 2.4.6 3.4-3.1-1.6-3.1 1.6.6-3.4-2.5-2.4 3.5-.5z"
              />
            </svg>
          </div>
          <div class="auth-mobile-brand-meta">
            <strong>POPSTAR <span>Connect</span></strong>
            <small>ระบบบริหารงานขาย &amp; สมาชิกร้านค้า</small>
          </div>
        </div>

        <!-- 2. ข้อความต้อนรับ & 3. หัวข้อเข้าสู่ระบบ -->
        <header class="auth-header">
          <p class="auth-welcome">{{ welcomeText }}</p>
          <h2 class="auth-login-title">{{ title }}</h2>
          <p class="auth-sub">{{ subtitle }}</p>
        </header>

        <div v-if="loading" class="auth-skeleton" aria-busy="true">
          <div class="skel skel-lg"></div>
          <div class="skel skel-lg"></div>
          <div class="skel skel-md" style="width: 68%"></div>
        </div>
        <slot v-else />
      </div>
    </section>
  </main>
</template>

<script setup>
import '../auth.css';

defineProps({
  welcomeText: { type: String, default: 'ยินดีต้อนรับสู่ POPSTAR Connect' },
  title: { type: String, required: true },
  subtitle: { type: String, required: true },
  loading: { type: Boolean, default: false },
});
</script>
