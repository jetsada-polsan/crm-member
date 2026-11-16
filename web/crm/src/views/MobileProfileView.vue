<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">MOBILE / IPAD FIELD OPS &amp; PROFILE</div>
        <h2>โปรไฟล์ &amp; ข้อมูลค้างส่ง</h2>
        <p>ข้อมูลของคุณ ปุ่มลัดงานออกตลาด และรายการที่บันทึกไว้ตอนไม่มีเน็ต</p>
      </div>
      <div class="head-actions">
        <button type="button" class="btn primary" @click="openQuickAdd()">＋ เพิ่มร้านใหม่</button>
        <button type="button" class="btn light" @click="openVisitWizard()">📸 บันทึกเข้าพบ</button>
      </div>
    </div>

    <div class="card panel-pad" style="margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <div>
          <h3 style="margin:0">ทางลัดปฏิบัติการภาคสนาม (Touch-Optimized)</h3>
          <small class="muted">ใช้งานได้รวดเร็วทั้งบน iPad และสมาร์ตโฟนในพื้นที่หน้างาน</small>
        </div>
        <span class="tag blue">{{ offlineDrafts.length }} Offline Drafts</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px">
        <RouterLink to="/quick-add" class="btn primary" style="min-height:46px">➕ Quick Add Store</RouterLink>
        <RouterLink to="/visits" class="btn light" style="min-height:46px">📸 บันทึกเข้าพบร้านค้า</RouterLink>
        <RouterLink to="/plans" class="btn" style="min-height:46px">📅 แผนออกตลาด</RouterLink>
        <RouterLink to="/followups" class="btn" style="min-height:46px">✅ งานติดตามผล</RouterLink>
        <RouterLink to="/map" class="btn" style="min-height:46px">🗺️ แผนที่ &amp; ปักหมุด</RouterLink>
        <RouterLink to="/customer-360" class="btn" style="min-height:46px">🔍 Customer 360°</RouterLink>
      </div>
    </div>

    <div class="split">
      <div class="card panel-pad">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
          <div class="avatar" style="width:50px;height:50px;font-size:20px">{{ userInitial }}</div>
          <div>
            <h3 style="margin:0">{{ me?.name }}</h3>
            <div class="small muted">{{ me?.email }}</div>
            <span class="tag blue" style="margin-top:4px">{{ me?.role }} {{ me?.salesperson_code ? `(${me.salesperson_code})` : '' }}</span>
          </div>
        </div>
        <div style="display:grid;gap:10px">
          <button type="button" class="btn light" @click="uiState.commandPaletteOpen = true">⌘ เปิด Command Palette (Ctrl/Cmd+K)</button>
          <RouterLink to="/reports" class="btn">📊 ดูรายงานการขาย &amp; รายงานเข้าพบ</RouterLink>
          <RouterLink v-if="isManager" to="/erp-requests" class="btn">📨 คิวขอเปิดบัญชี ERP</RouterLink>
          <RouterLink v-if="isManager" to="/matching" class="btn">🔗 จับคู่ลูกค้า ERP Matching</RouterLink>
          <RouterLink v-if="isManager" to="/members" class="btn">🎁 สมาชิก &amp; Member Registry</RouterLink>
          <RouterLink v-if="isManager" to="/loyalty" class="btn">💎 กติกา &amp; บัญชีคะแนน</RouterLink>
          <RouterLink v-if="isManager" to="/settings" class="btn">⚙️ Settings &amp; Access Control</RouterLink>
          <RouterLink v-if="isManager" to="/sync" class="btn">⇄ ERP Sync Monitor</RouterLink>
          <button type="button" class="btn danger" @click="confirmLogout">⎋ ออกจากระบบ</button>
        </div>
      </div>

      <div class="card panel-pad">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px">
          <div>
            <h3 style="margin:0">Offline Draft Queue ({{ offlineDrafts.length }})</h3>
            <small class="muted">ซิงค์ข้อมูลเข้าพบที่บันทึกไว้ระหว่างอับสัญญาณ</small>
          </div>
          <button
            v-if="offlineDrafts.length"
            type="button"
            class="btn primary"
            :disabled="offlineSyncing"
            @click="syncPendingOfflineDrafts"
          >
            {{ offlineSyncing ? 'กำลังซิงค์...' : 'ซิงค์ทั้งหมด' }}
          </button>
        </div>
        <div v-for="d in offlineDrafts" :key="d.client_draft_id" class="minirow">
          <div>
            <b>{{ d.shopLabel || d.client_draft_id }}</b>
            <div class="small muted">{{ d.updatedAt }}</div>
          </div>
          <span class="tag orange">รอซิงค์</span>
        </div>
        <div v-if="!offlineDrafts.length" class="small muted">ไม่มีข้อมูล Draft ค้างส่ง</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { RouterLink } from 'vue-router';
import { askConfirm, uiState } from '../../../shared/composables/useUiStore.js';
import {
  isManager,
  logoutStaff,
  me,
  offlineDrafts,
  offlineSyncing,
  openQuickAdd,
  openVisitWizard,
  syncPendingOfflineDrafts,
  userInitial,
} from '../composables/useCrmSession.js';

async function confirmLogout() {
  const ok = await askConfirm({
    title: 'ออกจากระบบ',
    message: 'ต้องการออกจากระบบ POPSTAR Connect หรือไม่?',
    confirmLabel: 'ออกจากระบบ',
    tone: 'danger',
  });
  if (ok) await logoutStaff();
}
</script>
