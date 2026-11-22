<template>
  <main class="auth-shell">
    <!-- Desktop branding. The login form stays the primary task. -->
    <section class="auth-visual" aria-label="เกี่ยวกับ POPSTAR Connect">
      <header class="auth-head">
        <div class="auth-brand">
          <span class="auth-mark" aria-hidden="true">P</span>
          <div>
            <strong>POPSTAR <span>Connect</span></strong>
            <small>Business Platform</small>
          </div>
        </div>
        <span class="auth-secure"><span class="auth-secure-dot" aria-hidden="true"></span> สำหรับผู้มีสิทธิ์ใช้งาน</span>
      </header>

      <div class="auth-hero">
        <span class="auth-eyebrow">ONE CONNECTED WORKSPACE</span>
        <h1>ทุกความสัมพันธ์<br />กับลูกค้า <span>สำคัญเสมอ</span></h1>
        <p>จัดการร้านค้า วางแผนออกตลาด ติดตามงานขาย และดูแลสมาชิกในพื้นที่ทำงานเดียว</p>

        <div class="auth-feature-list">
          <span><span class="auth-feature-icon" aria-hidden="true">✓</span> ข้อมูลลูกค้าในที่เดียว</span>
          <span><span class="auth-feature-icon" aria-hidden="true">✓</span> บันทึกเข้าพบพร้อมพิกัด GPS</span>
          <span><span class="auth-feature-icon" aria-hidden="true">✓</span> ติดตามงานได้ทุกที่</span>
        </div>
      </div>

      <div class="auth-visual-decoration" aria-hidden="true">
        <div class="auth-decoration-line"></div>
        <div class="auth-workflow">
          <div class="auth-workflow-header">
            <span class="auth-workflow-mark">✦</span>
            <span>WORKFLOW</span>
            <span class="auth-workflow-online">● &nbsp; Connected</span>
          </div>
          <div class="auth-workflow-item">
            <span class="auth-workflow-number">01</span>
            <span>วางแผนเข้าพบลูกค้า</span>
            <span class="auth-workflow-check">✓</span>
          </div>
          <div class="auth-workflow-item">
            <span class="auth-workflow-number">02</span>
            <span>บันทึกข้อมูลหน้าร้าน</span>
            <span class="auth-workflow-check">✓</span>
          </div>
          <div class="auth-workflow-item">
            <span class="auth-workflow-number">03</span>
            <span>ติดตามโอกาสการขาย</span>
            <span class="auth-workflow-pending">→</span>
          </div>
        </div>
      </div>

      <footer class="auth-visual-foot">© 2026 POPSTAR CONNECT <span>Made for field sales.</span></footer>
    </section>

    <!-- On phones the brand moves here; large marketing visuals stay hidden. -->
    <section class="auth-login-area" aria-label="เข้าสู่ระบบ">
      <div class="auth-login-wrap">
        <div class="auth-mobile-brand">
          <span class="auth-mark" aria-hidden="true">P</span>
          <div><strong>POPSTAR <span>Connect</span></strong><small>ทีมขาย · ร้านค้า · สมาชิก</small></div>
        </div>

        <div class="auth-login-top">
          <span class="auth-tag">ยินดีต้อนรับสู่ POPSTAR Connect</span>
          <span class="auth-language" aria-label="ภาษาไทย">TH</span>
        </div>

        <h2 class="auth-login-title">{{ title }}</h2>
        <p class="auth-sub">{{ subtitle }}</p>

        <div v-if="loading" class="auth-loading" role="status" aria-live="polite" aria-label="กำลังตรวจสอบสถานะการเข้าสู่ระบบ">
          <div class="skel skel-lg"></div>
          <div class="skel skel-lg"></div>
          <div class="skel skel-md" style="width:68%"></div>
        </div>
        <slot v-else />

        <footer class="auth-login-footer">© 2026 POPSTAR Connect · เข้าถึงข้อมูลตามสิทธิ์บัญชี</footer>
      </div>
    </section>
  </main>
</template>

<script setup>
import '../auth.css';

defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, required: true },
  loading: { type: Boolean, default: false },
});
</script>
