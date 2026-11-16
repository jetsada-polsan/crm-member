<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">{{ isRegisterMode ? 'MEMBER REGISTRATION ONBOARDING' : 'MEMBER PORTAL SEPARATION' }}</div>
        <h2>{{ isRegisterMode ? 'วิธีสมัครสมาชิก' : 'เปิดหน้าสมาชิก' }}</h2>
        <p>หน้าสำหรับร้านค้าสมัครสมาชิก ดูบิล และแลกของรางวัล (แยกบัญชีจากทีมขาย)</p>
      </div>
      <div class="head-actions">
        <a :href="memberPortalUrl" target="_blank" rel="noopener noreferrer" class="btn primary">
          ↗ เปิดหน้าสมาชิก
        </a>
      </div>
    </div>

    <div class="notice">
      เพื่อความปลอดภัย: ระบบ CRM พนักงาน (Staff Guard) และ Member Portal (Member Guard) แยก Cookie, Session และ CSRF อิสระต่อกัน ลูกค้าสามารถสมัครด้วยเบอร์โทรศัพท์หรืออีเมล (รหัสผ่าน 12+ ตัวอักษร) แล้วรอทีมงานยืนยันกับบัญชี ERP ก่อนจึงจะเห็นบิลขายและคะแนน
    </div>

    <div class="kpis" style="margin-bottom:16px">
      <div class="card kpi">
        <div class="kpi-label">บัญชีสมาชิกทั้งหมด</div>
        <div class="kpi-value">{{ fmt((members || []).length) }}</div>
        <div class="kpi-foot">สมัครผ่าน Member Portal</div>
      </div>
      <div class="card kpi">
        <div class="kpi-label">ยืนยันบัญชี ERP แล้ว</div>
        <div class="kpi-value" style="color:var(--green)">{{ fmt(verifiedCount) }}</div>
        <div class="kpi-foot">เข้าถึงประวัติบิลและแต้มได้แล้ว</div>
      </div>
      <div class="card kpi">
        <div class="kpi-label">คำขอเชื่อมรอตรวจสอบ</div>
        <div class="kpi-value" style="color:var(--orange)">{{ fmt((verifications || []).length) }}</div>
        <div class="kpi-foot">รอ Admin/Manager อนุมัติ</div>
      </div>
    </div>

    <div class="split">
      <div class="card panel-pad">
        <h3 style="margin:0 0 10px">ขั้นตอนการสมัครและยืนยันตัวตนสมาชิก (4 ขั้น)</h3>
        <div class="timeline">
          <div class="timeline-item">
            <b>1. สมัครสมาชิกบน Member Portal (unverified)</b>
            <div class="small muted">ลูกค้าสมัครด้วยเบอร์โทรหรืออีเมล + รหัสผ่าน 12 ตัวอักษร + ยินยอม PDPA Consent</div>
          </div>
          <div class="timeline-item">
            <b>2. ส่งคำขอเชื่อมบัญชีร้านค้า / รับรหัส LINK CODE สำหรับ LINE</b>
            <div class="small muted">ลูกค้าระบุรหัสลูกค้า ERP หรือเบอร์ร้านค้าเพื่อส่งคำขอให้แอดมินตรวจสอบ</div>
          </div>
          <div class="timeline-item">
            <b>3. ผู้ดูแลระบบตรวจสอบและอนุมัติ (member_verified)</b>
            <div class="small muted">แอดมินตรวจสอบรหัส ERP และ Customer Master ในเมนู สมาชิก &amp; Member Registry แล้วกดอนุมัติ</div>
          </div>
          <div class="timeline-item">
            <b>4. แสดง Digital Member Card, บิลขาย Bplus และของรางวัล</b>
            <div class="small muted">ก่อนอนุมัติระบบจะบล็อก /api/member/invoices และ /api/member/points ด้วย HTTP 403 เสมอ</div>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
          <RouterLink to="/members" class="btn primary">ไปหน้าตรวจคำขอ &amp; สมาชิก →</RouterLink>
          <RouterLink to="/loyalty" class="btn light">ไปหน้ากติกาคะแนน</RouterLink>
        </div>
      </div>

      <div class="card panel-pad">
        <h3 style="margin:0 0 10px">URL สำหรับส่งต่อให้ร้านค้า / LINE OA</h3>
        <div class="minirow">
          <span>Member Portal URL</span>
          <b>{{ memberPortalUrl }}</b>
        </div>
        <div class="minirow">
          <span>Authentication Guard</span>
          <span class="tag purple">member (Separate Cookie)</span>
        </div>
        <div class="minirow">
          <span>รหัสผ่านขั้นต่ำ</span>
          <span class="tag blue">min:12 + PDPA Consent</span>
        </div>
        <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
          <button type="button" class="btn" @click="copyPortalUrl">📋 คัดลอกลิงก์ Member Portal</button>
          <a :href="memberPortalUrl" target="_blank" rel="noopener noreferrer" class="btn light">เปิดในแท็บใหม่ ↗</a>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, fmt, isManager } from '../composables/useCrmSession.js';

const route = useRoute();
const isRegisterMode = computed(() => route.meta?.mode === 'register');

const memberPortalUrl = computed(() => {
  if (typeof window === 'undefined') return 'http://localhost:18082/';
  return `${window.location.protocol}//${window.location.hostname}:18082/`;
});

const { data: members } = useQuery({
  queryKey: ['crm', 'members-handoff-count'],
  queryFn: ({ signal }) => crmGet('/members', { signal }),
  enabled: isManager,
});

const { data: verifications } = useQuery({
  queryKey: ['crm', 'verifications-handoff-pending'],
  queryFn: ({ signal }) => crmGet('/loyalty/verifications?status=pending', { signal }),
  enabled: isManager,
});

const verifiedCount = computed(
  () => (members.value || []).filter((m) => m.verification_status === 'verified').length
);

async function copyPortalUrl() {
  try {
    await navigator.clipboard.writeText(memberPortalUrl.value);
    toast.success('คัดลอกลิงก์ Member Portal แล้ว');
  } catch {
    toast.info(`Member Portal URL: ${memberPortalUrl.value}`);
  }
}
</script>
