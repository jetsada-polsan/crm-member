<template>
  <div>
    <PageHeader
      title="แผนออกตลาดประจำวัน"
      subtitle="วางแผนร้านที่ต้องไปเยี่ยมในแต่ละวัน"
    >
      <template v-if="isManager" #primary>
        <button type="button" class="btn primary" @click="modalOpen = true">＋ สร้างแผนออกตลาด</button>
      </template>
    </PageHeader>

    <SkeletonBlock v-if="isLoading && !(plans || []).length" variant="table" :rows="5" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดแผนออกตลาดไม่สำเร็จ' }}</div>

    <div v-else class="card">
      <div class="table-wrap desktop-table-only">
        <table class="table">
          <thead><tr><th>ID</th><th>วันที่</th><th>เส้นทาง / โซน</th><th>หมายเหตุ</th></tr></thead>
          <tbody>
            <tr v-for="p in plans || []" :key="p.id">
              <td><span class="tag blue">#{{ p.id }}</span></td>
              <td><b>{{ p.visit_date }}</b></td>
              <td><b>{{ p.route_name }}</b> <span class="small muted">{{ p.district || '' }}</span></td>
              <td style="white-space:normal">{{ p.note || '—' }}</td>
            </tr>
            <tr v-if="!(plans || []).length">
              <td colspan="4" class="placeholder">ยังไม่มีแผนออกตลาดในระบบ</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mob-cards panel-pad">
        <div v-for="p in plans || []" :key="`pm-${p.id}`" class="card mob-card">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <strong>{{ p.route_name }}</strong>
            <span class="tag blue">{{ p.visit_date }}</span>
          </div>
          <div v-if="p.district || p.note" class="small muted" style="margin-top:6px">
            {{ p.district || '' }} {{ p.note ? `· ${p.note}` : '' }}
          </div>
        </div>
        <div v-if="!(plans || []).length" class="placeholder">ยังไม่มีแผนออกตลาดในระบบ</div>
      </div>
    </div>

    <BaseModal :open="modalOpen" title="สร้างแผนออกตลาดใหม่" @close="modalOpen = false">
      <form id="planForm" class="form" @submit.prevent="createPlan">
        <div class="field span2"><label>ชื่อเส้นทาง *</label><input v-model="planForm.route_name" required placeholder="สายเหนือ-คลองหลวง" /></div>
        <div class="field"><label>วันที่ *</label><input v-model="planForm.visit_date" type="date" required /></div>
        <div class="field"><label>Staff User ID ผู้รับผิดชอบ *</label><input v-model.number="planForm.assigned_to" type="number" required /></div>
        <div class="field span2"><label>หมายเหตุ</label><input v-model="planForm.note" placeholder="เป้าหมายพื้นที่ / ร้านค้าสำคัญ" /></div>
      </form>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="submit" form="planForm" class="btn primary" :disabled="saving">{{ saving ? 'กำลังบันทึก...' : 'บันทึกแผน' }}</button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, isManager, me } from '../composables/useCrmSession.js';

const modalOpen = ref(false);
const saving = ref(false);
const planForm = ref({ route_name: '', visit_date: '', assigned_to: me.value?.id || 1, note: '' });

const { data: plans, isLoading, isError, error, refetch } = useQuery({
  queryKey: ['crm', 'plans'],
  queryFn: ({ signal }) => crmGet('/plans', { signal }),
});

async function createPlan() {
  saving.value = true;
  try {
    await crmMutate('/plans', 'POST', planForm.value);
    planForm.value = { route_name: '', visit_date: '', assigned_to: me.value?.id || 1, note: '' };
    modalOpen.value = false;
    toast.success('สร้างแผนออกตลาดเรียบร้อย');
    await refetch();
  } catch (err) {
    toast.error('สร้างแผนไม่สำเร็จ', err);
  } finally {
    saving.value = false;
  }
}
</script>
