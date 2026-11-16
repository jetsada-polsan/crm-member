<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">FIELD SALES ACQUISITION</div>
        <h2>เพิ่มร้านค้าใหม่ (Quick Add &amp; Duplicate Guard)</h2>
        <p>บันทึกร้านค้าใหม่จากหน้างานพร้อมเช็คชื่อ/เบอร์/พิกัดซ้ำ โดยแยกจากฐานข้อมูล Bplus ERP อย่างปลอดภัย</p>
      </div>
      <div class="head-actions">
        <button type="button" class="btn primary" @click="openQuickAdd()">＋ เปิดฟอร์มเพิ่มร้านใหม่ (3 ช่องด่วน)</button>
        <button type="button" class="btn light" @click="openVisitWizard()">📸 บันทึกเข้าพบร้านค้า</button>
      </div>
    </div>

    <div class="split">
      <div class="card panel-pad">
        <h3 style="margin:0 0 8px">บันทึกข้อมูลร้านใหม่แบบรวดเร็ว (Quick Add Mode)</h3>
        <p class="small muted" style="margin:0 0 14px">
          ออกแบบให้พนักงานขายกรอกเพียง 3 ช่องบังคับ (ชื่อร้าน, เบอร์โทร, ประเภทร้าน) พร้อมแนบ GPS และภาพถ่ายหน้าร้านที่บีบอัดอัตโนมัติ
        </p>
        <div class="timeline" style="margin-bottom:14px">
          <div class="timeline-item">
            <b>ตรวจสอบร้านซ้ำอัตโนมัติ (ERP + Lead)</b>
            <div class="small muted">แจ้งเตือนทันทีเมื่อชื่อร้านหรือเบอร์โทรคล้ายกับลูกค้า Bplus หรือ Lead เดิม</div>
          </div>
          <div class="timeline-item">
            <b>สร้าง Internal Customer Master แยกจาก ERP</b>
            <div class="small muted">ทุกร้านจะได้รหัส INT-CUS-... ไว้เก็บการเข้าพบและพิกัด โดยยังไม่เปิดบัญชี ERP จนกว่าฝ่ายขายจะส่งคำขอ</div>
          </div>
          <div class="timeline-item">
            <b>รองรับงานภาคสนามและ Mobile Viewport</b>
            <div class="small muted">ปุ่มขนาดใหญ่ แตะง่าย ใช้งานได้ทันทีจากแท็บเล็ต iPad หรือมือถือ</div>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button type="button" class="btn primary" style="min-height:46px" @click="openQuickAdd()">
            ➕ เริ่มเพิ่มร้านค้าใหม่ตอนนี้
          </button>
          <RouterLink to="/pipeline" class="btn">ดู Lead Pipeline →</RouterLink>
        </div>
      </div>

      <div class="card panel-pad">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px">
          <h3 style="margin:0">ร้านค้าใหม่ล่าสุดในระบบ</h3>
          <button type="button" class="btn" @click="refetch">⟳ รีเฟรช</button>
        </div>
        <SkeletonBlock v-if="isLoading && !leads.length" variant="cards" :rows="3" />
        <div v-for="l in leads" :key="l.id" class="minirow">
          <div>
            <b>{{ l.shop_name }}</b>
            <div class="small muted">{{ l.phone || '—' }} · {{ l.shop_type || 'ร้านค้า' }} · {{ l.province || '—' }}</div>
          </div>
          <div style="display:flex;gap:6px;align-items:center">
            <span class="tag" :class="stageTagClass(l.pipeline_stage)">{{ stageLabel(l.pipeline_stage) }}</span>
            <button type="button" class="btn light" @click="openDetailDrawer('lead', l.id)">เปิดดู</button>
          </div>
        </div>
        <div v-if="!isLoading && !leads.length" class="small muted">ยังไม่มี Lead ล่าสุด</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import {
  crmGet,
  openDetailDrawer,
  openQuickAdd,
  openVisitWizard,
  stageLabel,
  stageTagClass,
} from '../composables/useCrmSession.js';

const { data, isLoading, refetch } = useQuery({
  queryKey: ['crm', 'quick-add-recent-leads'],
  queryFn: ({ signal }) => crmGet('/leads?paginate=1&page=1&per_page=8', { signal }),
});

const leads = computed(() => data.value?.data || []);
</script>
