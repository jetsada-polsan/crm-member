<template>
  <main class="auth-shell">
    <section class="auth-visual" aria-label="POPSTAR Connect">
      <div class="auth-glow"></div>
      <div class="auth-head">
        <div class="auth-brand">
          <div class="auth-mark">P</div>
          <div>
            <strong>POPSTAR Connect</strong>
            <small>ทีมขาย · ร้านค้า · สมาชิก</small>
          </div>
        </div>
        <div class="auth-secure" title="เซสชันแยกสิทธิ์และป้องกัน CSRF">
          <i></i>
          <span class="auth-secure-text">SECURE PLATFORM</span>
        </div>
      </div>

      <div class="auth-hero">
        <div class="auth-eyebrow">POPSTAR CONNECT</div>
        <h1>เชื่อมทีมขาย<br /><span>กับลูกค้าทุกร้าน</span></h1>
        <p>ออกตลาด บันทึกเข้าพบ ติดตามโอกาสขาย และดูแลสมาชิกสะสมแต้ม ครบในแอปเดียว ใช้งานง่ายบนมือถือ</p>
      </div>

      <div class="auth-experience" aria-hidden="true">
        <div class="auth-fake-app">
          <div class="auth-fake-top"><i></i><i></i><i></i><b>POPSTAR / BUSINESS OVERVIEW</b></div>
          <div class="auth-fake-content">
            <div class="auth-fake-side">
              <strong>WORKSPACE</strong>
              <div class="auth-fake-nav active">◈ &nbsp; Dashboard</div>
              <div class="auth-fake-nav">▣ &nbsp; Customers</div>
              <div class="auth-fake-nav">◇ &nbsp; Field Sales</div>
              <div class="auth-fake-nav">▤ &nbsp; Analytics</div>
            </div>
            <div class="auth-fake-main">
              <header><b>Business Overview</b><span>● &nbsp; Workspace</span></header>
              <div class="auth-fake-kpis">
                <div><small>Customer 360°</small><strong>4 Domains</strong><em>↗ isolated</em></div>
                <div><small>Field Sales</small><strong>Mobile</strong><em>↗ capture</em></div>
                <div><small>ERP Sync</small><strong>Read-Only</strong><em>↗ verified</em></div>
              </div>
              <div class="auth-fake-chart">
                <span style="height:32%"></span>
                <span style="height:57%"></span>
                <span style="height:45%"></span>
                <span style="height:66%"></span>
                <span style="height:60%"></span>
                <span style="height:81%"></span>
                <span style="height:68%"></span>
                <span style="height:92%"></span>
                <span style="height:75%"></span>
                <span style="height:100%"></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="auth-features">
        <span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6" /></svg>
          Smart Customer 360°
        </span>
        <span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6" /></svg>
          Field Sales Ready
        </span>
        <span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6" /></svg>
          Unified Member Experience
        </span>
      </div>

      <div class="auth-visual-foot">
        <span>© 2026 POPSTAR CONNECT</span>
        <span>DESIGNED FOR GROWTH ✦</span>
      </div>
    </section>

    <section class="auth-login-area">
      <div class="auth-login-wrap">
        <div class="auth-login-top">
          <span class="auth-tag">✦ &nbsp; NEXT-GEN WORKSPACE</span>
          <span class="auth-language" aria-label="ภาษาไทย">🌐 &nbsp; TH</span>
        </div>

        <h2 class="auth-login-title">{{ title }}</h2>
        <p class="auth-sub">{{ subtitle }}</p>

        <div v-if="loading" style="display:grid;gap:14px">
          <div class="skel skel-lg"></div>
          <div class="skel skel-lg"></div>
          <div class="skel skel-md" style="width:68%"></div>
        </div>
        <slot v-else />
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
