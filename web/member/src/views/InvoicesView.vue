<template>
  <div>
    <PageHeader
      title="ประวัติการซื้อ"
      subtitle="บิลซื้อของร้านคุณ (แสดงหลังยืนยันร้านค้าแล้ว)"
    />

    <div v-if="!isMemberVerified" class="card panel-pad">
      <div class="empty">
        <div class="em">🔒</div>
        <strong>ประวัติการซื้อยังไม่เปิดให้เข้าถึง</strong>
        <p class="small muted" style="margin:8px 0 16px">
          เพื่อความปลอดภัย ข้อมูลบิลขายและยอดซื้อจาก Bplus ERP จะเปิดดูได้หลังผู้จัดการตรวจสอบและอนุมัติการเป็นเจ้าของบัญชีร้านค้าเท่านั้น
        </p>
        <RouterLink to="/binding" class="btn primary">ไปหน้าส่งคำขอยืนยันร้านค้า</RouterLink>
      </div>
    </div>

    <template v-else>
      <div class="head-filter">
        <input v-model.trim="q" type="search" placeholder="ค้นหาเลขที่บิล หรือสาขา..." style="min-width:240px" />
        <select v-model="typeFilter">
          <option value="all">ทุกประเภทเอกสาร</option>
          <option value="sale">ใบขาย / บิลปกติ</option>
          <option value="return">ใบลดหนี้ / คืนสินค้า</option>
        </select>
      </div>

      <div class="card">
        <div v-if="isLoading" class="panel-pad"><SkeletonBlock variant="table" :rows="6" /></div>
        <div v-else-if="!filtered.length" class="placeholder">ไม่พบเอกสารยอดซื้อ</div>
        <template v-else>
          <div class="table-wrap desktop-table-only">
            <table class="table">
              <thead>
                <tr>
                  <th>วันที่เอกสาร</th>
                  <th>เลขที่บิล</th>
                  <th>ประเภท</th>
                  <th>รายการสินค้า / จำนวน</th>
                  <th style="text-align:right">ยอดสุทธิ (บาท)</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="inv in filtered" :key="inv.invoice_no">
                  <td>{{ inv.sold_at }}</td>
                  <td><b>{{ inv.invoice_no }}</b></td>
                  <td>
                    <span :class="['tag', inv.doc_category === 'return' ? 'red' : 'green']">
                      {{ inv.doc_category === 'return' ? `ลดหนี้ (${inv.dt_properties || 308})` : `ขาย (${inv.doc_type || 'IV'})` }}
                    </span>
                  </td>
                  <td><div v-for="item in inv.items || []" :key="item.line_no">{{ item.product_name || item.erp_product_code }} · {{ fmt(item.qty) }} {{ item.unit_name || '' }}</div></td>
                  <td style="text-align:right"><b>฿{{ fmt(inv.net_amount) }}</b></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mob-cards panel-pad">
            <div v-for="inv in filtered" :key="`m-${inv.invoice_no}`" class="card mob-card">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <strong>{{ inv.invoice_no }}</strong>
                <span :class="['tag', inv.doc_category === 'return' ? 'red' : 'green']">
                  {{ inv.doc_category === 'return' ? 'ลดหนี้' : 'ขาย' }}
                </span>
              </div>
              <div class="small muted">วันที่: {{ inv.sold_at }} · สาขา {{ inv.branch_code || '-' }}</div>
              <div v-for="item in inv.items || []" :key="item.line_no" class="small muted">{{ item.product_name || item.erp_product_code }} · {{ fmt(item.qty) }} {{ item.unit_name || '' }}</div>
              <div style="margin-top:8px;font-size:15px"><strong>฿{{ fmt(inv.net_amount) }}</strong></div>
            </div>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { fmt, isMemberVerified, memberGet } from '../composables/useMemberSession.js';

const q = ref('');
const typeFilter = ref('all');

const { data: invoices, isLoading } = useQuery({
  queryKey: ['member', 'invoices'],
  queryFn: ({ signal }) => memberGet('/invoices', { signal }),
  enabled: isMemberVerified,
});

const filtered = computed(() => {
  const rows = invoices.value || [];
  const text = q.value.toLowerCase();
  return rows.filter((x) => {
    if (typeFilter.value === 'sale' && x.doc_category === 'return') return false;
    if (typeFilter.value === 'return' && x.doc_category !== 'return') return false;
    if (!text) return true;
    return `${x.invoice_no} ${x.branch_code || ''} ${x.doc_type || ''}`.toLowerCase().includes(text);
  });
});
</script>
