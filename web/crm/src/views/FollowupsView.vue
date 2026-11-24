<template>
  <div>
    <PageHeader title="งานติดตามผล" subtitle="งานที่ต้องโทรหรือกลับไปเยี่ยมร้าน กดปิดงานได้ทันที">
      <template #primary>
        <button type="button" class="btn primary" @click="taskModalOpen = true">＋ เพิ่มงานติดตาม</button>
      </template>
    </PageHeader>

    <SkeletonBlock v-if="isLoading && !(tasks || []).length" variant="table" :rows="6" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดงานติดตามไม่สำเร็จ' }}</div>

    <div v-else class="card">
      <div class="table-wrap desktop-table-only">
        <table class="table">
          <thead>
            <tr>
              <th>ร้านค้า</th>
              <th>งานที่ต้องทำ</th>
              <th>กำหนดเสร็จ</th>
              <th>สถานะ</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in tasks || []" :key="t.id">
              <td>
                <b>{{ t.lead_shop_name || (t.crm_lead_id ? `LEAD-${t.crm_lead_id}` : 'ลูกค้าทั่วไป') }}</b>
              </td>
              <td style="white-space: normal">{{ t.title }}</td>
              <td>{{ t.due_date || '—' }}</td>
              <td>
                <span class="tag" :class="t.status === 'done' ? 'green' : 'orange'">{{ t.status }}</span>
              </td>
              <td>
                <button v-if="t.status !== 'done'" type="button" class="btn light" @click="completeTask(t.id)">
                  ✓ ปิดงาน
                </button>
              </td>
            </tr>
            <tr v-if="!(tasks || []).length">
              <td colspan="5" class="placeholder">ยังไม่มีงานติดตามผลในขณะนี้</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mob-cards" style="padding: 12px">
        <div v-for="t in tasks || []" :key="t.id" class="card mob-card">
          <div style="display: flex; justify-content: space-between; gap: 8px">
            <strong>{{ t.title }}</strong>
            <span class="tag" :class="t.status === 'done' ? 'green' : 'orange'">{{ t.status }}</span>
          </div>
          <div class="small muted" style="margin-top: 4px">
            {{ t.lead_shop_name || 'ทั่วไป' }} · ครบกำหนด: {{ t.due_date || '—' }}
          </div>
          <div v-if="t.status !== 'done'" style="margin-top: 8px">
            <button type="button" class="btn light" style="width: 100%" @click="completeTask(t.id)">
              ✓ ปิดงานติดตามนี้
            </button>
          </div>
        </div>
        <div v-if="!(tasks || []).length" class="placeholder">ไม่มีงานติดตาม</div>
      </div>
    </div>

    <BaseModal :open="taskModalOpen" title="เพิ่มงานติดตามลูกค้าใหม่" @close="taskModalOpen = false">
      <form id="taskCreateForm" class="form" @submit.prevent="createTask">
        <div class="field span2">
          <label>ร้านค้าใหม่ (CRM Lead) *</label>
          <select v-model="taskForm.leadId" required>
            <option value="">-- เลือกร้านค้า --</option>
            <option v-for="l in leads || []" :key="l.id" :value="l.id">{{ l.shop_name }}</option>
          </select>
        </div>
        <div class="field span2">
          <label>หัวข้องาน *</label><input v-model="taskForm.title" required placeholder="โทรติดตามผลใบเสนอราคา" />
        </div>
        <div class="field span2">
          <label>วันครบกำหนด</label><NiceDateInput v-model="taskForm.due_date" placeholder="เลือกวันครบกำหนด..." />
        </div>
      </form>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="submit" form="taskCreateForm" class="btn primary" :disabled="creating">
          {{ creating ? 'กำลังเพิ่ม...' : 'บันทึกงาน' }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useMutation, useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import NiceDateInput from '../../../shared/components/NiceDateInput.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, crmQueryClient, invalidateCrmData } from '../composables/useCrmSession.js';

const taskModalOpen = ref(false);
const creating = ref(false);
const taskForm = ref({ leadId: '', title: '', due_date: '' });

const queryKey = ['crm', 'tasks'];

const {
  data: tasks,
  isLoading,
  isError,
  error,
  refetch,
} = useQuery({
  queryKey,
  queryFn: ({ signal }) => crmGet('/tasks', { signal }),
});

const { data: leads } = useQuery({
  queryKey: ['crm', 'tasks-leads'],
  queryFn: ({ signal }) => crmGet('/leads', { signal }),
});

const completeMutation = useMutation({
  mutationFn: (id) => crmMutate(`/tasks/${id}/complete`, 'POST', {}),
  onMutate: async (id) => {
    await crmQueryClient.cancelQueries({ queryKey });
    const prev = crmQueryClient.getQueryData(queryKey);
    crmQueryClient.setQueryData(queryKey, (old) =>
      Array.isArray(old) ? old.map((x) => (x.id === id ? { ...x, status: 'done' } : x)) : old
    );
    return { prev };
  },
  onError: (err, _id, ctx) => {
    if (ctx?.prev) crmQueryClient.setQueryData(queryKey, ctx.prev);
    toast.error('ปิดงานไม่สำเร็จ (คืนสถานะเดิมแล้ว)', err);
  },
  onSuccess: () => {
    toast.success('ปิดงานติดตามแล้ว');
    invalidateCrmData(['dashboard', 'tasks']);
  },
});

function completeTask(id) {
  completeMutation.mutate(id);
}

async function createTask() {
  creating.value = true;
  try {
    await crmMutate(`/leads/${taskForm.value.leadId}/tasks`, 'POST', {
      title: taskForm.value.title,
      due_date: taskForm.value.due_date || null,
    });
    taskModalOpen.value = false;
    taskForm.value = { leadId: '', title: '', due_date: '' };
    toast.success('เพิ่มงานติดตามแล้ว');
    await refetch();
    invalidateCrmData(['dashboard']);
  } catch (err) {
    toast.error('เพิ่มงานไม่สำเร็จ', err);
  } finally {
    creating.value = false;
  }
}
</script>
