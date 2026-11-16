<template>
  <div>
    <PageHeader
      title="ลูกค้าใน ERP"
      subtitle="ข้อมูลลูกค้าและยอดขายจาก Bplus (ดูได้อย่างเดียว ถ้าจะแก้ต้องแก้ใน Bplus)"
    >
      <template v-if="isManager" #secondary>
        <RouterLink to="/sync" class="btn light">🔄 สถานะดึงข้อมูล ERP</RouterLink>
      </template>
      <template #primary>
        <button type="button" class="btn primary" @click="refetch">⟳ รีเฟรช</button>
      </template>
    </PageHeader>

    <div class="notice">
      โหมด Read-Only ERP Mirror: รายชื่อลูกค้า รหัส AR และยอดขายมาจากฐานข้อมูล Bplus เท่านั้น หากต้องการเก็บข้อมูลภาคสนามเพิ่มให้เปิดผ่าน CRM Lead หรือบันทึกเข้าพบ
    </div>

    <div class="card panel-pad" style="margin-bottom:16px">
      <div class="head-filter" style="margin-bottom:0">
        <input
          v-model="searchRaw"
          style="min-width:220px;flex:1"
          placeholder="ค้นหารหัสลูกค้า ERP (AR...), ชื่อร้าน, จังหวัด, เบอร์โทร..."
        />
        <select v-model="erpStatus" @change="page = 1">
          <option value="all">ลูกค้า ERP ทั้งหมด</option>
          <option value="purchasing">มีบิลซื้อแล้ว</option>
          <option value="no_purchase">ยังไม่เคยเปิดบิล</option>
        </select>
        <select v-model.number="perPage" @change="page = 1">
          <option :value="15">15 / หน้า</option>
          <option :value="25">25 / หน้า</option>
          <option :value="50">50 / หน้า</option>
        </select>
      </div>
    </div>

    <SkeletonBlock v-if="isLoading && !rows.length" variant="table" :rows="7" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดข้อมูลลูกค้า ERP ไม่สำเร็จ' }}</div>

    <div v-else class="card">
      <div class="table-wrap desktop-table-only">
        <table class="table">
          <thead>
            <tr>
              <th>รหัส ERP</th>
              <th>ชื่อร้านค้าใน Bplus</th>
              <th>จังหวัด / เบอร์โทร</th>
              <th>พนักงานขาย</th>
              <th>บิลขาย</th>
              <th>ยอดซื้อสุทธิก่อน VAT</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="c in rows"
              :key="c.id"
              style="cursor:pointer"
              @click="openDetailDrawer('erp', c.id)"
            >
              <td><span class="tag gray">{{ c.erp_customer_code }}</span></td>
              <td><b>{{ c.customer_name }}</b></td>
              <td>{{ c.province || '—' }} · {{ c.phone || '—' }}</td>
              <td>{{ c.salesperson_name || c.salesperson_code || '—' }}</td>
              <td>{{ fmt(c.sale_invoice_count) }}</td>
              <td><b>{{ fmt(c.total_sales_net) }} ฿</b></td>
              <td>
                <div style="display:flex;gap:6px">
                  <button type="button" class="btn light" @click.stop="openDetailDrawer('erp', c.id)">ดูบิล/รายละเอียด</button>
                  <button type="button" class="btn primary" @click.stop="openVisitWizard({ kind: 'erp', id: c.id })">📸 เข้าพบ</button>
                </div>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="7" class="placeholder">ไม่พบลูกค้า ERP ที่ตรงเงื่อนไข</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mob-cards" style="padding:12px">
        <div
          v-for="c in rows"
          :key="`erp-m-${c.id}`"
          class="card mob-card"
          @click="openDetailDrawer('erp', c.id)"
        >
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
            <strong>{{ c.customer_name }}</strong>
            <span class="tag gray">{{ c.erp_customer_code }}</span>
          </div>
          <div class="small muted" style="margin:6px 0">
            {{ c.province || '—' }} · โทร {{ c.phone || '—' }} · {{ fmt(c.sale_invoice_count) }} บิลขายจริง
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <b>{{ fmt(c.total_sales_net) }} ฿</b>
            <button type="button" class="btn primary" style="padding:6px 10px" @click.stop="openVisitWizard({ kind: 'erp', id: c.id })">
              📸 เข้าพบ
            </button>
          </div>
        </div>
      </div>

      <div class="pager">
        <span>แสดง {{ rows.length }} จาก {{ fmt(meta.total) }} รายการ (หน้า {{ meta.current_page }} / {{ meta.last_page }})</span>
        <div class="pager-btns">
          <button type="button" class="btn" :disabled="page <= 1" @click="page--">← ก่อนหน้า</button>
          <button type="button" class="btn" :disabled="page >= meta.last_page" @click="page++">ถัดไป →</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { keepPreviousData, useQuery } from '@tanstack/vue-query';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { crmGet, fmt, isManager, openDetailDrawer, openVisitWizard } from '../composables/useCrmSession.js';

const searchRaw = ref('');
const search = ref('');
const erpStatus = ref('all');
const page = ref(1);
const perPage = ref(25);

let timer = null;
watch(searchRaw, (val) => {
  if (timer) clearTimeout(timer);
  timer = setTimeout(() => {
    search.value = val.trim();
    page.value = 1;
  }, 300);
});
onUnmounted(() => {
  if (timer) clearTimeout(timer);
});

const { data, isLoading, isError, error, refetch } = useQuery({
  queryKey: computed(() => ['crm', 'erp-customers-page', { page: page.value, perPage: perPage.value, search: search.value, erpStatus: erpStatus.value }]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({
      paginate: '1',
      page: String(page.value),
      per_page: String(perPage.value),
      status: erpStatus.value,
    });
    if (search.value) p.set('q', search.value);
    return crmGet(`/customers?${p.toString()}`, { signal });
  },
  placeholderData: keepPreviousData,
});

const rows = computed(() => data.value?.data || []);
const meta = computed(() => data.value?.meta || { current_page: 1, last_page: 1, total: 0 });
</script>
