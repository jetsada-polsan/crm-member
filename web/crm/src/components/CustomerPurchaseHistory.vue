<template>
  <section class="ph" aria-label="ประวัติการซื้อ">
    <!-- Data freshness warning (ERP sync behind) -->
    <div v-if="isStale" class="ph-stale">
      ⚠️ บิลในระบบมีถึงวันที่ <b>{{ thaiDate(insights.data_until) }}</b> เท่านั้น
      ยังไม่ได้ดึงบิลใหม่จาก Bplus — ตัวเลขด้านล่างอาจไม่ครบ
    </div>

    <!-- A. At-a-glance summary -->
    <div class="ph-summary">
      <div class="ph-stat" :class="{ warn: isLongSinceLastPurchase }">
        <span class="ph-stat-label">ซื้อครั้งล่าสุด</span>
        <b>{{ lastPurchaseText }}</b>
        <small>{{ insights?.last_purchase_at ? thaiDate(insights.last_purchase_at) : 'ยังไม่มีบิล' }}</small>
      </div>
      <div class="ph-stat">
        <span class="ph-stat-label">ยอดซื้อ 3 เดือนล่าสุด</span>
        <b>{{ fmt(insights?.recent_90_days?.net_before_vat) }} ฿</b>
        <small :class="trendClass">{{ trendText }}</small>
      </div>
      <div class="ph-stat">
        <span class="ph-stat-label">ความถี่</span>
        <b>{{ billsPerMonthText }}</b>
        <small>จาก {{ fmt(insights?.bills_last_12_months) }} บิลใน 12 เดือน</small>
      </div>
    </div>

    <!-- B. Talking points: regular products & ones that stopped -->
    <div v-if="insights?.lapsed_products?.length" class="ph-block ph-lapsed">
      <h5>🔔 เคยซื้อประจำ แต่หายไป — ลองถามลูกค้า</h5>
      <div v-for="p in insights.lapsed_products" :key="`lapsed-${p.erp_product_code}`" class="ph-product">
        <div class="ph-product-main">
          <b>{{ p.product_name || p.erp_product_code }}</b>
          <small>เคยซื้อ {{ fmt(p.bill_count) }} ครั้ง · ครั้งล่าสุด {{ thaiDate(p.last_bought_at) }}</small>
        </div>
        <span class="tag orange">{{ daysAgo(p.last_bought_at) }} วัน</span>
      </div>
    </div>

    <div class="ph-block">
      <h5>⭐ สินค้าที่ซื้อประจำ (12 เดือน)</h5>
      <div v-if="insightsLoading" class="skel skel-md" />
      <p v-else-if="!insights?.top_products?.length" class="small muted">ยังไม่มีข้อมูลสินค้า</p>
      <div v-for="p in insights?.top_products || []" :key="`top-${p.erp_product_code}`" class="ph-product">
        <div class="ph-product-main">
          <b>{{ p.product_name || p.erp_product_code }}</b>
          <small>ซื้อ {{ fmt(p.bill_count) }} บิล · รวม {{ fmt(p.total_qty) }} {{ p.unit_name || '' }}</small>
        </div>
        <small class="muted">ล่าสุด {{ thaiDate(p.last_bought_at) }}</small>
      </div>
    </div>

    <!-- C. Bill list -->
    <div class="ph-block">
      <div class="ph-list-head">
        <h5>🧾 บิลทั้งหมด <span class="muted">({{ fmt(totalBills) }})</span></h5>
        <div class="ph-range" role="group" aria-label="ช่วงเวลา">
          <button
            v-for="r in RANGES"
            :key="r.months"
            type="button"
            :class="{ active: months === r.months }"
            :aria-pressed="months === r.months"
            @click="months = r.months"
          >
            {{ r.label }}
          </button>
        </div>
      </div>

      <div v-if="billsLoading" class="skel skel-lg" />
      <div v-else-if="billsError" class="error">โหลดรายการบิลไม่สำเร็จ</div>
      <p v-else-if="!bills.length" class="small muted">ไม่มีบิลในช่วงเวลานี้</p>

      <button
        v-for="bill in bills"
        :key="bill.id"
        type="button"
        class="ph-bill"
        @click="openBill(bill)"
      >
        <span class="ph-bill-date">
          <b>{{ dayMonth(bill.sold_at) }}</b>
          <small>{{ yearShort(bill.sold_at) }}</small>
        </span>
        <span class="ph-bill-main">
          <b>{{ bill.invoice_no }}</b>
          <small>
            <span v-if="bill.doc_category === 'return'" class="tag red">ใบลดหนี้/คืนของ</span>
            {{ fmt(bill.item_count) }} รายการ
          </small>
        </span>
        <span class="ph-bill-amount" :class="{ neg: bill.doc_category === 'return' }">
          {{ fmt(bill.before_vat_amount ?? bill.net_amount) }} ฿
        </span>
        <span class="ph-chevron" aria-hidden="true">›</span>
      </button>

      <button
        v-if="hasNextPage"
        type="button"
        class="btn light ph-more"
        :disabled="isFetchingNextPage"
        @click="fetchNextPage()"
      >
        {{ isFetchingNextPage ? 'กำลังโหลด…' : 'โหลดบิลเพิ่ม' }}
      </button>
    </div>

    <!-- Bill detail: bottom sheet on phones, centred dialog on desktop -->
    <Teleport to="body">
      <Transition name="pop-fade">
        <div v-if="selectedBill" class="ph-overlay" @click.self="requestClose">
          <section
            ref="sheetRef"
            class="ph-sheet"
            role="dialog"
            aria-modal="true"
            :aria-label="`บิล ${selectedBill.invoice_no}`"
            tabindex="-1"
          >
            <div class="ph-sheet-handle" aria-hidden="true" />
            <header class="ph-sheet-head">
              <div>
                <h3>บิล {{ selectedBill.invoice_no }}</h3>
                <p class="small muted">
                  {{ thaiDate(selectedBill.sold_at) }}
                  <span v-if="selectedBill.salesperson_name">· {{ selectedBill.salesperson_name }}</span>
                </p>
              </div>
              <button class="close" type="button" aria-label="ปิด" @click="requestClose">✕</button>
            </header>

            <div class="ph-sheet-body">
              <div v-if="detailLoading" class="skel skel-lg" />
              <div v-else-if="detailError" class="error">โหลดรายการสินค้าไม่สำเร็จ</div>
              <template v-else-if="billDetail">
                <div
                  v-for="item in billDetail.items"
                  :key="item.line_no"
                  class="ph-line"
                  :class="{ muted: item.is_excluded_kpi }"
                >
                  <div class="ph-line-main">
                    <b>{{ item.product_name || item.erp_product_code }}</b>
                    <small>
                      {{ fmt(item.qty) }} {{ item.unit_name || '' }}
                      <template v-if="Number(item.unit_price)"> × {{ fmt(item.unit_price) }} ฿</template>
                      <template v-if="item.is_excluded_kpi"> · ค่าบริการ</template>
                    </small>
                  </div>
                  <b class="ph-line-amount">{{ fmt(item.before_vat_amount ?? item.net_amount) }} ฿</b>
                </div>
                <p v-if="!billDetail.items.length" class="small muted">บิลนี้ไม่มีรายการสินค้า</p>
              </template>
            </div>

            <footer v-if="billDetail" class="ph-sheet-foot">
              <div class="ph-total-row"><span>ก่อน VAT</span><span>{{ fmt(billDetail.invoice.before_vat_amount ?? billDetail.invoice.net_amount) }} ฿</span></div>
              <div class="ph-total-row"><span>VAT</span><span>{{ fmt(billDetail.invoice.vat_amount) }} ฿</span></div>
              <div class="ph-total-row grand"><span>รวมทั้งสิ้น</span><b>{{ fmt(billDetail.invoice.gross_amount ?? billDetail.invoice.net_amount) }} ฿</b></div>
            </footer>
          </section>
        </div>
      </Transition>
    </Teleport>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useInfiniteQuery, useQuery } from '@tanstack/vue-query';
import { useOverlayBehavior } from '../../../shared/composables/useOverlayBehavior.js';
import { crmGet, fmt } from '../composables/useCrmSession.js';

const props = defineProps({
  /** erp_customers.id */
  customerId: { type: [Number, String], required: true },
});

const RANGES = [
  { months: 3, label: '3 เดือน' },
  { months: 6, label: '6 เดือน' },
  { months: 12, label: '1 ปี' },
  { months: 0, label: 'ทั้งหมด' },
];
const PER_PAGE = 15;
const STALE_AFTER_DAYS = 7;
const LONG_GAP_DAYS = 60;

const months = ref(6);
const selectedBill = ref(null);
const sheetRef = ref(null);

const { data: insights, isLoading: insightsLoading } = useQuery({
  queryKey: computed(() => ['crm', 'purchase-insights', props.customerId]),
  queryFn: ({ signal }) => crmGet(`/customers/${props.customerId}/purchase-insights`, { signal }),
});

const {
  data: billPages,
  isLoading: billsLoading,
  isError: billsError,
  fetchNextPage,
  hasNextPage,
  isFetchingNextPage,
} = useInfiniteQuery({
  queryKey: computed(() => ['crm', 'customer-invoices', props.customerId, months.value]),
  queryFn: ({ pageParam, signal }) =>
    crmGet(`/customers/${props.customerId}/invoices?months=${months.value}&page=${pageParam}&per_page=${PER_PAGE}`, { signal }),
  initialPageParam: 1,
  getNextPageParam: (last) =>
    last?.meta && last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
});

const bills = computed(() => (billPages.value?.pages || []).flatMap((p) => p.data || []));
const totalBills = computed(() => billPages.value?.pages?.[0]?.meta?.total ?? 0);

const {
  data: billDetail,
  isLoading: detailLoading,
  isError: detailError,
} = useQuery({
  queryKey: computed(() => ['crm', 'customer-invoice', props.customerId, selectedBill.value?.id]),
  queryFn: ({ signal }) => crmGet(`/customers/${props.customerId}/invoices/${selectedBill.value.id}`, { signal }),
  enabled: computed(() => Boolean(selectedBill.value?.id)),
});

const { requestClose } = useOverlayBehavior({
  isOpen: () => Boolean(selectedBill.value),
  isDirty: () => false,
  onClose: () => {
    selectedBill.value = null;
  },
  panelRef: sheetRef,
});

watch(
  () => props.customerId,
  () => {
    selectedBill.value = null;
  }
);

function openBill(bill) {
  selectedBill.value = bill;
}

function toDate(value) {
  if (!value) return null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
}

function thaiDate(value) {
  const d = toDate(value);
  return d ? d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' }) : '—';
}

function dayMonth(value) {
  const d = toDate(value);
  return d ? d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' }) : '—';
}

function yearShort(value) {
  const d = toDate(value);
  return d ? d.toLocaleDateString('th-TH', { year: 'numeric' }) : '';
}

function daysAgo(value) {
  const d = toDate(value);
  if (!d) return '—';
  return Math.max(0, Math.floor((Date.now() - d.getTime()) / 86400000));
}

const isStale = computed(() => {
  const until = toDate(insights.value?.data_until);
  return Boolean(until) && (Date.now() - until.getTime()) / 86400000 > STALE_AFTER_DAYS;
});

const isLongSinceLastPurchase = computed(
  () => (insights.value?.days_since_last_purchase ?? 0) > LONG_GAP_DAYS
);

const lastPurchaseText = computed(() => {
  const days = insights.value?.days_since_last_purchase;
  if (days === null || days === undefined) return '—';
  if (days === 0) return 'วันนี้';
  return `${fmt(days)} วันที่แล้ว`;
});

const trend = computed(() => {
  const recent = Number(insights.value?.recent_90_days?.net_before_vat || 0);
  const previous = Number(insights.value?.previous_90_days?.net_before_vat || 0);
  if (!previous) return recent ? { pct: null, dir: 'up' } : null;
  const pct = Math.round(((recent - previous) / previous) * 100);
  return { pct, dir: pct >= 0 ? 'up' : 'down' };
});

const trendText = computed(() => {
  if (!trend.value) return 'ไม่มียอดเทียบ';
  if (trend.value.pct === null) return 'เพิ่งเริ่มซื้อช่วงนี้';
  const arrow = trend.value.dir === 'up' ? '▲' : '▼';
  return `${arrow} ${Math.abs(trend.value.pct)}% เทียบ 3 เดือนก่อน`;
});

const trendClass = computed(() => (trend.value?.dir === 'down' ? 'down' : trend.value ? 'up' : ''));

const billsPerMonthText = computed(() => {
  const n = Number(insights.value?.bills_last_12_months || 0);
  if (!n) return 'ยังไม่มีบิล';
  const perMonth = n / 12;
  return perMonth >= 1 ? `เดือนละ ~${fmt(Math.round(perMonth))} บิล` : `${fmt(n)} บิล/ปี`;
});
</script>

<style scoped>
.ph { display: grid; gap: 14px; }
.ph-stale {
  background: #fff7e6; border: 1px solid #fde2b0; color: #8a5a0b;
  border-radius: 12px; padding: 12px 14px; font-size: 14px; line-height: 1.55;
}
.ph-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.ph-stat {
  background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 12px;
  display: flex; flex-direction: column; gap: 2px; min-width: 0;
}
.ph-stat.warn { border-color: #f5c27a; background: #fffaf0; }
.ph-stat-label { font-size: 12.5px; color: var(--muted); }
.ph-stat b { font-size: 17px; line-height: 1.3; }
.ph-stat small { font-size: 12.5px; color: var(--muted); }
.ph-stat small.up { color: var(--green); font-weight: 600; }
.ph-stat small.down { color: var(--red); font-weight: 600; }

.ph-block { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 14px; }
.ph-block h5 { margin: 0 0 8px; font-size: 15.5px; }
.ph-lapsed { border-color: #f5c27a; background: #fffaf0; }

.ph-product {
  display: flex; justify-content: space-between; align-items: center; gap: 10px;
  padding: 10px 0; border-bottom: 1px solid #edf1f6;
}
.ph-product:last-child { border-bottom: 0; }
.ph-product-main { min-width: 0; display: flex; flex-direction: column; }
.ph-product-main b { font-size: 14.5px; line-height: 1.4; }
.ph-product-main small { font-size: 12.5px; color: var(--muted); }

.ph-list-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
.ph-list-head h5 { margin: 0; }
.ph-range { display: inline-flex; background: #eef2f8; border-radius: 12px; padding: 3px; gap: 2px; }
.ph-range button {
  border: 0; background: transparent; border-radius: 9px; padding: 6px 11px; min-height: 36px;
  font-size: 13.5px; color: var(--muted); font-weight: 600;
}
.ph-range button.active { background: #fff; color: var(--blue); box-shadow: 0 1px 4px rgba(20, 33, 59, .12); }

.ph-bill {
  width: 100%; display: flex; align-items: center; gap: 12px; text-align: left;
  border: 0; border-bottom: 1px solid #edf1f6; background: transparent;
  padding: 12px 2px; min-height: 60px; color: var(--ink);
}
.ph-bill:last-of-type { border-bottom: 0; }
.ph-bill:hover, .ph-bill:focus-visible { background: #f7f9ff; }
.ph-bill-date {
  width: 52px; flex-shrink: 0; text-align: center; background: var(--blue-soft); color: var(--blue);
  border-radius: 11px; padding: 6px 2px; display: flex; flex-direction: column; line-height: 1.2;
}
.ph-bill-date b { font-size: 13.5px; }
.ph-bill-date small { font-size: 11.5px; opacity: .85; }
.ph-bill-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.ph-bill-main b { font-size: 14.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ph-bill-main small { font-size: 12.5px; color: var(--muted); display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.ph-bill-amount { font-weight: 700; font-size: 15px; white-space: nowrap; }
.ph-bill-amount.neg { color: var(--red); }
.ph-chevron { color: #9aa6ba; font-size: 22px; line-height: 1; }
.ph-more { width: 100%; margin-top: 8px; }

/* Bill detail overlay — above the customer drawer (z-index 90) */
.ph-overlay {
  position: fixed; inset: 0; z-index: 115; background: rgba(17, 27, 51, .5);
  display: flex; align-items: center; justify-content: center; padding: 16px;
}
.ph-sheet {
  width: min(560px, 100%); max-height: 88vh; background: #fff; border-radius: 20px;
  display: flex; flex-direction: column; overflow: hidden; outline: none;
  box-shadow: 0 24px 60px rgba(17, 27, 51, .3);
}
.ph-sheet-handle { display: none; }
.ph-sheet-head {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
  padding: 16px 18px 12px; border-bottom: 1px solid var(--border);
}
.ph-sheet-head h3 { margin: 0; font-size: 18px; }
.ph-sheet-head p { margin: 2px 0 0; }
.ph-sheet-body { overflow: auto; padding: 4px 18px; flex: 1; -webkit-overflow-scrolling: touch; }
.ph-line {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
  padding: 12px 0; border-bottom: 1px solid #edf1f6;
}
.ph-line:last-child { border-bottom: 0; }
.ph-line.muted { opacity: .7; }
.ph-line-main { min-width: 0; display: flex; flex-direction: column; }
.ph-line-main b { font-size: 15px; line-height: 1.4; }
.ph-line-main small { font-size: 13px; color: var(--muted); }
.ph-line-amount { white-space: nowrap; font-size: 15px; }
.ph-sheet-foot {
  border-top: 1px solid var(--border); background: #f8fafd;
  padding: 12px 18px calc(14px + env(safe-area-inset-bottom, 0px));
}
.ph-total-row { display: flex; justify-content: space-between; font-size: 14px; color: var(--muted); padding: 2px 0; }
.ph-total-row.grand { color: var(--ink); font-size: 17px; padding-top: 6px; }

@media (max-width: 800px) {
  .ph-summary { grid-template-columns: 1fr 1fr; }
  .ph-summary .ph-stat:first-child { grid-column: span 2; }
  .ph-overlay { align-items: flex-end; padding: 0; }
  .ph-sheet { width: 100%; max-height: 90vh; border-radius: 22px 22px 0 0; }
  .ph-sheet-handle { display: block; width: 44px; height: 5px; background: #cbd5e1; border-radius: 99px; margin: 8px auto 0; }
}
</style>
