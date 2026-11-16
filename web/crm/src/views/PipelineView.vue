<template>
  <div>
    <PageHeader
      title="โอกาสการขาย"
      subtitle="ติดตามร้านใหม่ทีละขั้น ตั้งแต่ติดต่อครั้งแรกจนเปิดรหัสใน ERP"
    >
      <template #primary>
        <button type="button" class="btn primary" @click="openQuickAdd()">＋ เพิ่มร้านใหม่</button>
      </template>
    </PageHeader>

    <SkeletonBlock v-if="isLoading && !leadsList.length" variant="cards" :rows="4" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลด Pipeline ไม่สำเร็จ' }}</div>

    <div v-else class="kanban">
      <div v-for="lane in LANES" :key="lane.id" class="lane">
        <h4>
          <span>{{ lane.title }}</span>
          <span class="tag" :class="lane.tag">{{ laneLeads(lane.id).length }}</span>
        </h4>

        <div
          v-for="l in laneLeads(lane.id)"
          :key="l.id"
          class="lead"
          style="cursor:pointer"
          @click="openDetailDrawer('lead', l.id)"
        >
          <strong>{{ l.shop_name }}</strong>
          <p>{{ l.shop_type || 'ร้านค้า' }} · {{ l.phone || 'ไม่มีเบอร์' }}</p>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap">
            <span class="tag" :class="stageTagClass(l.pipeline_stage)">{{ stageLabel(l.pipeline_stage) }}</span>
            <select
              :value="l.pipeline_stage"
              style="font-size:14px;padding:6px 10px;min-height:40px;border-radius:10px;border:1px solid var(--border);background:#fff"
              @click.stop
              @change.stop="moveStage(l, $event.target.value)"
            >
              <option v-for="st in PIPELINE_STAGES" :key="st.id" :value="st.id">{{ st.label }}</option>
            </select>
          </div>
          <div
            v-if="!['converted', 'erp_creation_requested', 'erp_match_pending'].includes(l.pipeline_stage)"
            style="margin-top:8px"
          >
            <button
              type="button"
              class="btn light"
              style="width:100%;font-size:14px;padding:8px 10px"
              @click.stop="requestErp(l)"
            >
              📨 ขอเปิดบัญชี ERP
            </button>
          </div>
        </div>

        <div v-if="!laneLeads(lane.id).length" class="small muted" style="text-align:center;padding:24px 8px">
          ยังไม่มีรายการในสถานะนี้
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useMutation, useQuery } from '@tanstack/vue-query';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import {
  crmGet,
  crmMutate,
  crmQueryClient,
  invalidateCrmData,
  openDetailDrawer,
  openQuickAdd,
  PIPELINE_STAGES,
  stageLabel,
  stageTagClass,
} from '../composables/useCrmSession.js';

const LANES = [
  { id: 'new', title: 'ลูกค้าใหม่', tag: 'blue' },
  { id: 'follow', title: 'กำลังติดตาม / เสนอราคา', tag: 'orange' },
  { id: 'visited', title: 'อนุมัติ / เข้าพบแล้ว', tag: 'green' },
  { id: 'erp_pending', title: 'ขอเปิด / จับคู่ ERP', tag: 'purple' },
  { id: 'converted', title: 'ซื้อจริง / เชื่อม ERP', tag: 'gray' },
];

const queryKey = ['crm', 'pipeline-leads'];

const { data: leadsData, isLoading, isError, error } = useQuery({
  queryKey,
  queryFn: ({ signal }) => crmGet('/leads', { signal }),
});

const leadsList = computed(() => leadsData.value || []);

function laneLeads(laneId) {
  if (laneId === 'new') return leadsList.value.filter((l) => l.pipeline_stage === 'new_lead' && l.approval_status !== 'approved');
  if (laneId === 'follow') return leadsList.value.filter((l) => ['contacted', 'interested', 'quoted', 'follow_up'].includes(l.pipeline_stage));
  if (laneId === 'visited') return leadsList.value.filter((l) => l.approval_status === 'approved' && !['converted', 'erp_creation_requested', 'erp_match_pending'].includes(l.pipeline_stage));
  if (laneId === 'erp_pending') return leadsList.value.filter((l) => ['erp_creation_requested', 'erp_match_pending'].includes(l.pipeline_stage));
  if (laneId === 'converted') return leadsList.value.filter((l) => l.pipeline_stage === 'converted');
  return [];
}

const stageMutation = useMutation({
  mutationFn: ({ leadId, stage }) => crmMutate(`/leads/${leadId}/stage`, 'PATCH', { pipeline_stage: stage }),
  onMutate: async ({ leadId, stage }) => {
    await crmQueryClient.cancelQueries({ queryKey });
    const previous = crmQueryClient.getQueryData(queryKey);
    crmQueryClient.setQueryData(queryKey, (old) =>
      Array.isArray(old) ? old.map((x) => (x.id === leadId ? { ...x, pipeline_stage: stage } : x)) : old
    );
    return { previous };
  },
  onError: (err, _vars, ctx) => {
    if (ctx?.previous) crmQueryClient.setQueryData(queryKey, ctx.previous);
    toast.error('ย้ายสถานะ Pipeline ไม่สำเร็จ (คืนค่าเดิมแล้ว)', err);
  },
  onSuccess: () => {
    toast.success('อัปเดตสถานะ Pipeline แล้ว');
    invalidateCrmData(['leads', 'master', 'dashboard']);
  },
});

function moveStage(lead, stage) {
  stageMutation.mutate({ leadId: lead.id, stage });
}

async function requestErp(lead) {
  const ok = await askConfirm({
    title: 'ส่งคำขอเปิดรหัสลูกค้า Bplus ERP',
    message: `ส่งร้าน "${lead.shop_name}" เข้าคิวขอเปิดบัญชี ERP โดยไม่สร้างรหัสลงฐาน ERP อัตโนมัติ?`,
    confirmLabel: 'ส่งคำขอ',
  });
  if (!ok) return;
  try {
    await crmMutate(`/customer-master/leads/${lead.id}/request-erp-creation`, 'POST', {
      note: 'ส่งคำขอเปิดบัญชี ERP จากหน้า Lead Pipeline',
    });
    toast.success('ส่งคำขอเปิดบัญชี ERP เรียบร้อย');
    invalidateCrmData(['leads', 'master', 'dashboard']);
  } catch (err) {
    toast.error('ส่งคำขอไม่สำเร็จ', err);
  }
}
</script>
