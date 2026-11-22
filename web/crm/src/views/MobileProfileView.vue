<template>
  <div class="profile-page">
    <PageHeader
      title="บัญชีของฉัน"
      subtitle="ข้อมูลผู้ใช้งาน งานที่ใช้บ่อย และข้อมูลที่รอส่ง"
    />

    <!-- Account identity: calm, compact and readable on every screen -->
    <section class="profile-identity" aria-label="ข้อมูลบัญชีผู้ใช้งาน">
      <div class="profile-avatar" aria-hidden="true">{{ userInitial }}</div>
      <div class="profile-identity-content">
        <h2>{{ me?.name || 'ผู้ใช้งาน POPSTAR' }}</h2>
        <p v-if="me?.email" class="profile-email">{{ me.email }}</p>
        <div class="profile-badges">
          <span class="profile-role">{{ roleName }}</span>
          <span v-if="me?.salesperson_code" class="profile-code">รหัสเซลส์ {{ me.salesperson_code }}</span>
        </div>
      </div>
    </section>

    <div class="profile-layout">
      <div class="profile-main">
        <section class="profile-section" aria-labelledby="profile-actions-heading">
          <div class="profile-section-head">
            <div>
              <h3 id="profile-actions-heading">ทำรายการด่วน</h3>
              <p>งานที่ใช้บ่อยเมื่อออกพบลูกค้า</p>
            </div>
          </div>
          <div class="profile-primary-actions">
            <button type="button" class="profile-action profile-action-primary" @click="openVisitWizard()">
              <span class="profile-action-icon" aria-hidden="true">📸</span>
              <span><strong>บันทึกเข้าพบ</strong><small>ถ่ายรูปและบันทึก GPS</small></span>
              <span class="profile-action-arrow" aria-hidden="true">›</span>
            </button>
            <button type="button" class="profile-action" @click="openQuickAdd()">
              <span class="profile-action-icon" aria-hidden="true">🏪</span>
              <span><strong>เพิ่มร้านค้าใหม่</strong><small>บันทึกลูกค้าใหม่</small></span>
              <span class="profile-action-arrow" aria-hidden="true">›</span>
            </button>
          </div>
          <nav class="profile-shortcuts" aria-label="ทางลัดการทำงาน">
            <RouterLink to="/plans" class="profile-shortcut"><span aria-hidden="true">📅</span><span>แผนออกตลาด</span></RouterLink>
            <RouterLink to="/followups" class="profile-shortcut"><span aria-hidden="true">✅</span><span>งานติดตาม</span></RouterLink>
            <RouterLink to="/map" class="profile-shortcut"><span aria-hidden="true">🗺️</span><span>แผนที่ร้านค้า</span></RouterLink>
            <RouterLink to="/customer-360" class="profile-shortcut"><span aria-hidden="true">🔎</span><span>ข้อมูลลูกค้า 360°</span></RouterLink>
          </nav>
        </section>

        <section class="profile-section" aria-labelledby="profile-tools-heading">
          <div class="profile-section-head">
            <div>
              <h3 id="profile-tools-heading">เครื่องมือและรายงาน</h3>
              <p>ค้นหาข้อมูลและติดตามผลการขาย</p>
            </div>
          </div>
          <nav class="profile-link-list" aria-label="เครื่องมือทั่วไป">
            <RouterLink to="/reports" class="profile-row-link">
              <span class="profile-row-symbol" aria-hidden="true">📊</span>
              <span class="profile-row-copy"><strong>รายงานยอดขายและเข้าพบ</strong><small>ดูผลการทำงานและสรุปรายงาน</small></span>
              <span class="profile-link-chevron" aria-hidden="true">›</span>
            </RouterLink>
          </nav>
          <button type="button" class="profile-row-link profile-row-button" @click="uiState.commandPaletteOpen = true">
            <span class="profile-row-symbol" aria-hidden="true">🔍</span>
            <span class="profile-row-copy"><strong>ค้นหาเมนูทั้งหมด</strong><small>ค้นหาหน้าและเครื่องมือในระบบ</small></span>
            <span class="profile-link-chevron" aria-hidden="true">›</span>
          </button>
        </section>
      </div>

      <div class="profile-side">
        <!-- Offline status is important, but should not occupy a giant empty card. -->
        <section class="profile-section profile-offline" aria-labelledby="profile-offline-heading">
          <div class="profile-section-head">
            <div>
              <h3 id="profile-offline-heading">ข้อมูลรอส่ง</h3>
              <p>รายการที่บันทึกขณะไม่มีอินเทอร์เน็ต</p>
            </div>
            <span class="profile-draft-count" :class="{ pending: offlineDrafts.length > 0 }">
              {{ offlineDrafts.length }} รายการ
            </span>
          </div>

          <template v-if="offlineDrafts.length">
            <div class="profile-drafts">
              <div v-for="draft in offlineDrafts" :key="draft.client_draft_id" class="profile-draft-row">
                <div>
                  <strong>{{ draft.shopLabel || draft.client_draft_id }}</strong>
                  <small v-if="draft.updatedAt">{{ draft.updatedAt }}</small>
                </div>
                <span class="profile-draft-status">รอส่ง</span>
              </div>
            </div>
            <button type="button" class="profile-sync-button" :disabled="offlineSyncing" @click="syncPendingOfflineDrafts">
              {{ offlineSyncing ? 'กำลังส่งข้อมูล…' : 'ส่งข้อมูลค้างทั้งหมด' }}
            </button>
          </template>
          <div v-else class="profile-empty-queue">
            <span class="profile-empty-icon" aria-hidden="true">✓</span>
            <div><strong>ไม่มีรายการค้างส่ง</strong><small>รายการที่บันทึกออฟไลน์จะปรากฏที่นี่</small></div>
          </div>
        </section>

        <section v-if="isManager" class="profile-section" aria-labelledby="profile-admin-heading">
          <div class="profile-section-head">
            <div>
              <h3 id="profile-admin-heading">สำหรับผู้ดูแลระบบ</h3>
              <p>จัดการสมาชิกและการเชื่อมต่อ ERP</p>
            </div>
          </div>
          <details class="profile-admin-expand">
            <summary>แสดงเมนูจัดการ <span aria-hidden="true">⌄</span></summary>
            <nav class="profile-admin-nav" aria-label="เมนูสำหรับผู้ดูแลระบบ">
              <RouterLink to="/erp-requests">คำขอเปิดรหัสลูกหนี้ <span aria-hidden="true">›</span></RouterLink>
              <RouterLink to="/matching">จับคู่ลูกค้ากับ ERP <span aria-hidden="true">›</span></RouterLink>
              <RouterLink to="/members">ทะเบียนสมาชิก <span aria-hidden="true">›</span></RouterLink>
              <RouterLink to="/loyalty">กติกาสะสมแต้ม <span aria-hidden="true">›</span></RouterLink>
              <RouterLink to="/sync">สถานะดึงข้อมูล ERP <span aria-hidden="true">›</span></RouterLink>
              <RouterLink to="/settings">ตั้งค่าระบบและสิทธิ์ <span aria-hidden="true">›</span></RouterLink>
            </nav>
          </details>
        </section>

        <button type="button" class="profile-logout" @click="confirmLogout">
          <span aria-hidden="true">⎋</span> ออกจากระบบ
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import PageHeader from '../../../shared/components/PageHeader.vue';
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

const roleName = computed(() => {
  const names = {
    admin: 'ผู้ดูแลระบบ',
    manager: 'ผู้จัดการ',
    executive: 'ผู้บริหาร',
    salesperson: 'พนักงานขาย',
    sales: 'พนักงานขาย',
    staff: 'พนักงาน',
  };
  return names[me.value?.role] || me.value?.role || 'ผู้ใช้งาน';
});

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

<style scoped>
.profile-page{max-width:1140px;margin:0 auto;min-width:0}
.profile-page :deep(.pagehead){margin-bottom:16px}
.profile-identity{display:flex;align-items:center;gap:16px;padding:20px 22px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:16px;min-width:0}
.profile-avatar{display:grid;place-items:center;flex:none;width:64px;height:64px;border-radius:20px;background:#e0f2fe;color:#0369a1;font-size:26px;font-weight:700}
.profile-identity-content{min-width:0}
.profile-identity-content h2{margin:0 0 2px;font-size:21px;line-height:1.4;overflow-wrap:anywhere}
.profile-email{margin:0;color:var(--muted);font-size:13px;overflow-wrap:anywhere}
.profile-badges{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}
.profile-role,.profile-code{font-size:12px;line-height:1.4;padding:4px 9px;border-radius:7px;background:#eff6ff;color:#0369a1;font-weight:600}
.profile-code{background:#f1f5f9;color:#475569}
.profile-layout{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(300px,.85fr);gap:16px;align-items:start}
.profile-main,.profile-side{display:grid;gap:16px;min-width:0}
.profile-section{padding:18px 20px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);min-width:0}
.profile-section-head{display:flex;align-items:start;justify-content:space-between;gap:12px;margin-bottom:14px}
.profile-section-head h3{font-size:16px;line-height:1.4;margin:0;font-weight:700}
.profile-section-head p{font-size:12.5px;line-height:1.5;margin:3px 0 0;color:var(--muted)}
.profile-primary-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.profile-action{display:flex;align-items:center;gap:10px;text-align:left;background:#f8fafc;border:1px solid var(--border);border-radius:12px;min-width:0;min-height:80px;padding:12px;color:var(--ink);transition:border-color .15s,background-color .15s}
.profile-action-primary{background:#eff6ff;border-color:#bfdbfe}
.profile-action:hover{border-color:#93c5fd;background:#f0f9ff}
.profile-action-icon{font-size:23px;line-height:1}
.profile-action>span:nth-child(2){min-width:0;flex:1}
.profile-action strong{display:block;font-size:14px;line-height:1.5;font-weight:650}
.profile-action small{display:block;font-size:11.5px;color:var(--muted);line-height:1.5;margin-top:2px}
.profile-action-arrow,.profile-link-chevron{font-size:23px;line-height:1;color:#94a3b8;flex:none}
.profile-shortcuts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:12px}
.profile-shortcut{min-width:0;min-height:46px;padding:10px;border:1px solid var(--border);border-radius:10px;color:#334155;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:550}
.profile-shortcut span:first-child{font-size:17px;flex:none}
.profile-shortcut:hover,.profile-row-link:hover{background:#f8fafc;color:#0369a1}
.profile-link-list{display:grid}
.profile-row-link{display:flex;align-items:center;gap:12px;padding:12px 2px;color:var(--ink);min-height:56px;min-width:0;text-align:left}
.profile-row-link+.profile-row-link,.profile-row-button{border-top:1px solid var(--border)}
.profile-row-button{width:100%;background:transparent;border-left:0;border-right:0;border-bottom:0;border-radius:0;font:inherit;cursor:pointer}
.profile-row-symbol{flex:none;display:grid;place-items:center;width:36px;height:36px;border-radius:10px;background:#f1f5f9;font-size:17px}
.profile-row-copy{flex:1;min-width:0}
.profile-row-copy strong,.profile-row-copy small{display:block;line-height:1.45}
.profile-row-copy strong{font-size:13.5px;font-weight:650}
.profile-row-copy small{font-size:12px;color:var(--muted);margin-top:2px}
.profile-draft-count{flex:none;padding:4px 9px;background:#f1f5f9;color:#475569;border-radius:8px;font-size:12px;font-weight:650;white-space:nowrap}
.profile-draft-count.pending{background:#fff7ed;color:#9a3412}
.profile-empty-queue{display:flex;align-items:center;gap:10px;border-top:1px solid var(--border);padding:14px 0 0}
.profile-empty-icon{display:grid;place-items:center;flex:none;width:34px;height:34px;border-radius:50%;background:#ecfdf5;color:#047857;font-size:17px}
.profile-empty-queue strong,.profile-empty-queue small{display:block;line-height:1.5}
.profile-empty-queue strong{font-size:13px;font-weight:650}
.profile-empty-queue small{font-size:12px;color:var(--muted)}
.profile-drafts{border-top:1px solid var(--border)}
.profile-draft-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 0;border-bottom:1px solid var(--border)}
.profile-draft-row>div{min-width:0}
.profile-draft-row strong,.profile-draft-row small{display:block;overflow-wrap:anywhere}
.profile-draft-row strong{font-size:13px}
.profile-draft-row small{font-size:12px;color:var(--muted);margin-top:3px}
.profile-draft-status{color:#9a3412;font-size:11px;font-weight:650;background:#fff7ed;border-radius:7px;padding:4px 7px;white-space:nowrap}
.profile-sync-button{background:#0284c7;color:white;border:0;width:100%;border-radius:10px;min-height:44px;padding:10px 14px;margin-top:12px;font-size:13px;font-weight:700}
.profile-admin-expand{border:1px solid var(--border);border-radius:10px;overflow:hidden}
.profile-admin-expand summary{list-style:none;display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:46px;padding:11px 13px;cursor:pointer;font-weight:650;color:#334155;font-size:13px}
.profile-admin-expand summary::-webkit-details-marker{display:none}
.profile-admin-expand[open] summary span{transform:rotate(180deg)}
.profile-admin-nav{border-top:1px solid var(--border);padding:2px 12px}
.profile-admin-nav a{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:44px;padding:9px 0;color:#334155;font-size:13px}
.profile-admin-nav a+a{border-top:1px solid var(--border)}
.profile-admin-nav a span{font-size:20px;color:#94a3b8}
.profile-logout{display:flex;align-items:center;justify-content:center;gap:8px;padding:11px 14px;min-height:44px;width:100%;border-radius:11px;background:var(--surface);border:1px solid #fecdd3;color:#be123c;font-size:13.5px;font-weight:650}
.profile-logout:hover{background:#fff1f2}
.profile-logout span{font-size:20px;line-height:1}
@media(max-width:980px){.profile-layout{grid-template-columns:minmax(0,1fr)}.profile-side{grid-template-columns:minmax(0,1fr)}}
@media(max-width:800px){
  .profile-page :deep(.pagehead){margin-bottom:12px}
  .profile-identity{padding:16px;gap:12px;margin-bottom:12px;border-radius:14px}
  .profile-avatar{width:54px;height:54px;border-radius:15px;font-size:23px}
  .profile-identity-content h2{font-size:17px}
  .profile-layout,.profile-main,.profile-side{gap:12px}
  .profile-section{padding:14px;border-radius:14px}
  .profile-section-head{margin-bottom:12px}
  .profile-primary-actions{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .profile-action{gap:7px;padding:12px 10px;min-height:92px;align-items:flex-start;flex-direction:column}
  .profile-action-icon{font-size:21px}
  .profile-action-arrow{display:none}
  .profile-action strong{font-size:13px}
  .profile-action small{font-size:11px}
  .profile-shortcuts{gap:7px;margin-top:10px}
  .profile-shortcut{padding:9px;min-height:46px;font-size:12px}
  .profile-shortcut span:first-child{font-size:16px}
  .profile-logout{margin-bottom:8px}
}
@media(max-width:360px){.profile-shortcuts{grid-template-columns:1fr}.profile-identity{align-items:flex-start}.profile-avatar{width:46px;height:46px;font-size:20px}.profile-primary-actions{grid-template-columns:1fr}.profile-action{flex-direction:row;align-items:center;min-height:66px}}
@media(prefers-reduced-motion:reduce){.profile-action{transition:none}}
</style>
