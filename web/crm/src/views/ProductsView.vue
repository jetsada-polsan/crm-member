<template>
  <div class="catalog-page">
    <PageHeader
      class="desktop-only"
      title="สินค้าและราคา"
      subtitle="ค้นหาราคาและหน่วยขายจาก Bplus ERP"
    >
    </PageHeader>

    <!-- One concise summary replaces repetitive KPI cards. -->
    <div class="catalog-summary" role="status" aria-live="polite">
      <div class="catalog-summary-count">
        <strong>{{ fmt(summary.total_matched_skus) }}</strong>
        <span>รายการที่พบ</span>
        <small>จากสินค้าเปิดใช้งาน {{ fmt(summary.total_active_skus) }} รายการ</small>
      </div>
      <div class="catalog-summary-sync">
        <span class="catalog-status-dot" aria-hidden="true"></span>
        ราคาปกติ · ชุดราคา 0
        <small>อัปเดต {{ priceSyncLabel }}</small>
      </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="head-filter catalog-filter-bar">
      <div class="catalog-search-box">
        <input
          id="product-search"
          v-model="searchInput"
          type="search"
          aria-label="ค้นหาสินค้า"
          placeholder="ค้นหาชื่อสินค้า / SKU / บาร์โค้ด"
          @input="onSearchInput"
        />
      </div>

      <select v-model="selectedCategory" aria-label="กรองตามหมวดหมู่สินค้า" @change="onFilterChanged">
        <option value="">ทุกหมวดสินค้า ({{ fmt(summary.total_active_skus) }})</option>
        <option v-for="cat in categories" :key="cat.name" :value="cat.name">
          {{ cat.name }} ({{ fmt(cat.count) }})
        </option>
      </select>

      <select v-model.number="perPage" aria-label="จำนวนรายการต่อหน้า" @change="page = 1">
        <option :value="20">20 รายการ / หน้า</option>
        <option :value="50">50 รายการ / หน้า</option>
        <option :value="100">100 รายการ / หน้า</option>
        <option :value="200">200 รายการ / หน้า</option>
      </select>

      <button
        v-if="searchInput || selectedCategory"
        type="button"
        class="btn light"
        @click="resetFilters"
      >
        ล้างตัวกรอง
      </button>
    </div>

    <!-- Main Product Catalog Table -->
    <div class="card catalog-table-card">
      <SkeletonBlock v-if="isLoading && !groupedProducts.length" variant="table" :rows="8" />
      <div v-else-if="isError" class="panel-pad">
        <div class="error" style="margin-bottom:0">โหลดข้อมูลสินค้าและราคาไม่สำเร็จ กรุณาลองใหม่อีกครั้ง</div>
      </div>

      <template v-else>
        <!-- Desktop Table -->
        <div class="table-wrap desktop-table-only">
          <table class="table catalog-table">
            <thead>
              <tr>
                <th style="width:66px;text-align:center">ลำดับ</th>
                <th style="min-width:270px">ชื่อสินค้า</th>
                <th style="min-width:130px">ชื่อหน่วย</th>
                <th style="min-width:135px">หน่วยใหญ่</th>
                <th style="min-width:130px;text-align:right">ราคาหน่วยใหญ่</th>
                <th style="min-width:135px">หน่วยย่อย</th>
                <th style="min-width:130px;text-align:right">ราคาหน่วยย่อย</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in groupedProducts" :key="item.erp_product_code">
                <td style="text-align:center">
                  <span class="row-num-badge">{{ fmt(item.row_no) }}</span>
                </td>

                <td style="white-space:normal">
                  <div class="prod-name-cell">
                    <strong class="prod-title">{{ item.product_name }}</strong>
                    <div class="prod-meta-tags">
                      <span class="tag gray">{{ item.erp_product_code }}</span>
                      <span v-if="item.category_name" class="tag blue">{{ item.category_name }}</span>
                    </div>
                  </div>
                </td>

                <td style="white-space:normal">
                  <div class="unit-summary-text">{{ item.unit_summary || '—' }}</div>
                  <div v-if="(item.extra_units || []).length" class="extra-units-wrap">
                    <span v-for="(eu, i) in item.extra_units" :key="i" class="extra-unit-chip">
                      {{ eu.unit }}: {{ money(eu.price) }} ฿
                    </span>
                  </div>
                </td>

                <!-- หน่วยใหญ่ -->
                <td style="white-space:normal">
                  <template v-if="item.large_unit">
                    <div class="unit-cell">
                      <span class="unit-name large">{{ item.large_unit.unit }}</span>
                      <span v-if="item.large_unit.unit_qty && Number(item.large_unit.unit_qty) > 1" class="unit-qty-chip">
                        ×{{ fmtQty(item.large_unit.unit_qty) }}
                      </span>
                    </div>
                    <div v-if="(item.large_unit.goods_codes || []).length" class="goods-code-sub">
                      {{ item.large_unit.goods_codes[0] }}
                    </div>
                  </template>
                  <span v-else class="muted">—</span>
                </td>

                <!-- ราคาหน่วยใหญ่ -->
                <td style="text-align:right">
                  <template v-if="item.large_unit">
                    <span class="price-pill large">
                      {{ money(item.large_unit.price) }} ฿
                    </span>
                  </template>
                  <span v-else class="muted">—</span>
                </td>

                <!-- หน่วยย่อย -->
                <td style="white-space:normal">
                  <template v-if="item.small_unit">
                    <div class="unit-cell">
                      <span class="unit-name small-u">{{ item.small_unit.unit }}</span>
                    </div>
                    <div v-if="(item.small_unit.goods_codes || []).length" class="goods-code-sub">
                      {{ item.small_unit.goods_codes[0] }}
                    </div>
                  </template>
                  <span v-else class="muted">—</span>
                </td>

                <!-- ราคาหน่วยย่อย -->
                <td style="text-align:right">
                  <template v-if="item.small_unit">
                    <span class="price-pill small-u">
                      {{ money(item.small_unit.price) }} ฿
                    </span>
                  </template>
                  <span v-else class="muted">—</span>
                </td>
              </tr>

              <tr v-if="!groupedProducts.length">
                <td colspan="7" class="placeholder">ไม่พบรายการสินค้าที่ตรงกับคำค้นหา</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile-first compact, scannable product rows -->
        <div class="mob-cards catalog-mobile-list" role="list" aria-label="รายการสินค้า">
          <article v-for="item in groupedProducts" :key="'mobile-' + item.erp_product_code" class="catalog-mobile-item" role="listitem">
            <div class="catalog-mobile-heading">
              <strong class="catalog-mobile-name">{{ item.product_name }}</strong>
              <div class="catalog-mobile-meta">
                <span>SKU {{ item.erp_product_code }}</span>
                <span v-if="item.category_name" class="catalog-mobile-category">{{ item.category_name }}</span>
              </div>
            </div>

            <div class="catalog-mobile-prices">
              <div v-if="item.small_unit" class="catalog-mobile-price">
                <span>หน่วยย่อย · {{ item.small_unit.unit }}</span>
                <strong>{{ money(item.small_unit.price) }} ฿</strong>
              </div>
              <div v-if="item.large_unit" class="catalog-mobile-price">
                <span>หน่วยใหญ่ · {{ item.large_unit.unit }}</span>
                <strong>{{ money(item.large_unit.price) }} ฿</strong>
              </div>
              <div v-if="!item.small_unit && !item.large_unit" class="catalog-mobile-price">
                <span>ราคา</span>
                <strong>ไม่มีข้อมูล</strong>
              </div>
            </div>

            <details
              v-if="(item.extra_units || []).length || (item.small_unit?.goods_codes || []).length || (item.large_unit?.goods_codes || []).length"
              class="catalog-mobile-details"
            >
              <summary>ดูรหัสขายและหน่วยเพิ่มเติม</summary>
              <div v-if="(item.small_unit?.goods_codes || []).length">
                บาร์โค้ดหน่วยย่อย: {{ item.small_unit.goods_codes.join(', ') }}
              </div>
              <div v-if="(item.large_unit?.goods_codes || []).length">
                บาร์โค้ดหน่วยใหญ่: {{ item.large_unit.goods_codes.join(', ') }}
              </div>
              <div v-for="(extra, i) in item.extra_units || []" :key="i">
                {{ extra.unit }} · {{ money(extra.price) }} ฿
              </div>
            </details>
          </article>

          <div v-if="!groupedProducts.length" class="placeholder catalog-no-results">
            ไม่พบสินค้า ลองค้นหาด้วยชื่อ รหัสสินค้า หรือเปลี่ยนหมวดหมู่
          </div>
        </div>

        <!-- Pagination Footer -->
        <div v-if="meta.total > 0" class="pager">
          <div>
            แสดงลำดับ <b>{{ fmt(meta.from) }} – {{ fmt(meta.to) }}</b> จากทั้งหมด
            <b>{{ fmt(meta.total) }}</b> รายการ
            <span v-if="meta.total !== summary.total_active_skus" class="muted">
              (จากสินค้าเปิดใช้งานทั้งหมด {{ fmt(summary.total_active_skus) }} รายการ)
            </span>
          </div>
          <div class="pager-btns">
            <button type="button" class="btn light" :disabled="page <= 1" @click="page--">← ก่อนหน้า</button>
            <span class="small" style="font-weight:700;padding:0 6px">หน้า {{ meta.current_page }} / {{ meta.last_page }}</span>
            <button type="button" class="btn light" :disabled="page >= meta.last_page" @click="page++">ถัดไป →</button>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onUnmounted, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { crmGet, fmt, money } from '../composables/useCrmSession.js';

const search = ref('');
const searchInput = ref('');
let searchTimer = null;
const selectedCategory = ref('');
const page = ref(1);
const perPage = ref(typeof window !== 'undefined' && window.matchMedia('(max-width: 800px)').matches ? 20 : 50);

const { data, isLoading, isError } = useQuery({
  queryKey: computed(() => ['crm', 'product-catalog', search.value, selectedCategory.value, page.value, perPage.value]),
  queryFn: ({ signal }) => {
    const params = new URLSearchParams();
    if (search.value.trim()) params.set('q', search.value.trim());
    if (selectedCategory.value) params.set('category', selectedCategory.value);
    params.set('page', String(page.value));
    params.set('per_page', String(perPage.value));
    return crmGet(`/product-catalog?${params.toString()}`, { signal });
  },
  placeholderData: (prev) => prev,
});

const groupedProducts = computed(() => data.value?.grouped_products || []);
const categories = computed(() => data.value?.categories || []);
const summary = computed(() => data.value?.summary || {
  total_active_skus: 0,
  total_matched_skus: 0,
  total_price_rows: 0,
  multi_unit_skus: 0,
});
const meta = computed(() => data.value?.meta || {
  current_page: 1,
  last_page: 1,
  per_page: perPage.value,
  total: 0,
  from: 0,
  to: 0,
});

const priceSyncLabel = computed(() =>
  data.value?.price_synced_at
    ? new Date(data.value.price_synced_at).toLocaleString('th-TH', { timeZone: 'Asia/Bangkok', dateStyle: 'medium', timeStyle: 'short' })
    : 'ยังไม่มีข้อมูล'
);

function fmtQty(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return '';
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '');
}

function onFilterChanged() {
  page.value = 1;
}

function onSearchInput() {
  if (searchTimer !== null) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    search.value = searchInput.value;
    page.value = 1;
    searchTimer = null;
  }, 300);
}

onUnmounted(() => {
  if (searchTimer !== null) clearTimeout(searchTimer);
});

function resetFilters() {
  if (searchTimer !== null) clearTimeout(searchTimer);
  searchTimer = null;
  searchInput.value = '';
  search.value = '';
  selectedCategory.value = '';
  page.value = 1;
}
</script>

<style scoped>
.catalog-total-pill {
  background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%);
  color: #ffffff;
  border-radius: 999px;
  padding: 10px 18px;
  font-size: 14px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  gap: 6px;
  max-width: 100%;
  box-shadow: 0 6px 18px rgba(2, 132, 199, 0.24);
}

.catalog-total-pill strong {
  font-size: 16px;
  font-weight: 800;
}

.catalog-filter-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.catalog-search-box {
  flex: 1 1 240px;
  min-width: 0;
  width: 100%;
}

.catalog-search-box input {
  width: 100%;
}

.catalog-table-card {
  overflow: hidden;
}

.catalog-table th {
  position: sticky;
  top: 0;
  z-index: 2;
}

.row-num-badge {
  display: inline-grid;
  place-items: center;
  min-width: 32px;
  height: 28px;
  padding: 0 8px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #475569;
  font-size: 12.5px;
  font-weight: 700;
}

.prod-name-cell {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.prod-title {
  font-size: 14.5px;
  color: var(--ink);
  line-height: 1.4;
}

.prod-meta-tags {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.unit-summary-text {
  font-size: 13.5px;
  font-weight: 600;
  color: #334155;
}

.extra-units-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 5px;
}

.extra-unit-chip {
  font-size: 11.5px;
  font-weight: 600;
  color: #475569;
  background: #f1f5f9;
  border-radius: 999px;
  padding: 2px 8px;
}

.unit-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.unit-name {
  font-size: 13.5px;
  font-weight: 700;
}

.unit-name.large {
  color: #b45309;
}

.unit-name.small-u {
  color: #0369a1;
}

.unit-qty-chip {
  font-size: 11.5px;
  font-weight: 700;
  color: #92400e;
  background: #fef3c7;
  border-radius: 999px;
  padding: 1px 7px;
}

.goods-code-sub {
  font-size: 11.5px;
  color: #94a3b8;
  margin-top: 2px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.price-pill {
  display: inline-block;
  border-radius: 999px;
  padding: 5px 12px;
  font-size: 13.5px;
  font-weight: 800;
  white-space: nowrap;
}

.price-pill.large {
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fde68a;
}

.price-pill.small-u {
  background: #e0f2fe;
  color: #0369a1;
  border: 1px solid #bae6fd;
}

.prod-mob-card {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.prod-mob-head {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}

.prod-mob-prices {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.prod-mob-box {
  border-radius: 14px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.prod-mob-box.large-box {
  background: #fffbeb;
  border: 1px solid #fde68a;
}

.prod-mob-box.small-box {
  background: #f0f9ff;
  border: 1px solid #bae6fd;
}

@media (max-width: 800px) {
  .mob-prod-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 11px 14px;
    margin-bottom: 12px;
    border-radius: 16px;
    background: #e0f2fe;
    color: #0369a1;
    font-size: 13px;
    font-weight: 600;
  }
}

@media (max-width: 380px) {
  .prod-mob-prices {
    grid-template-columns: 1fr;
  }
}

/* Catalog: compact visual hierarchy, mobile-first list without nested cards. */
.catalog-summary{display:flex;align-items:center;justify-content:space-between;gap:12px 20px;flex-wrap:wrap;background:#fff;border:1px solid var(--border);border-radius:14px;padding:12px 18px;margin-bottom:14px}
.catalog-summary-count{display:flex;align-items:baseline;gap:9px;flex-wrap:wrap;color:#334155;font-size:14px}
.catalog-summary-count strong{font-size:24px;line-height:1.3;color:#0f172a;font-weight:750}
.catalog-summary-count small{font-size:12px;color:#64748b}
.catalog-summary-sync{display:flex;align-items:center;gap:7px;font-size:13px;color:#334155}
.catalog-summary-sync small{font-size:12px;color:#64748b;margin-left:6px}
.catalog-status-dot{width:8px;height:8px;flex:none;border-radius:50%;background:#0d9488}
.catalog-mobile-list{padding:0!important}
.catalog-mobile-item{padding:14px 16px;border-bottom:1px solid #e9eef5;background:#fff}
.catalog-mobile-item:last-of-type{border-bottom:0}
.catalog-mobile-heading{min-width:0}
.catalog-mobile-name{display:block;color:#172033;font-size:15px;font-weight:650;line-height:1.45;overflow-wrap:anywhere}
.catalog-mobile-meta{display:flex;align-items:center;flex-wrap:wrap;gap:4px 9px;margin-top:3px;font-size:12px;color:#64748b}
.catalog-mobile-category{max-width:100%;overflow:hidden;text-overflow:ellipsis}
.catalog-mobile-prices{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:9px}
.catalog-mobile-price{min-width:0;display:flex;flex-direction:column;gap:1px}
.catalog-mobile-price:only-child{grid-column:1/-1}
.catalog-mobile-price span{font-size:12px;color:#64748b;overflow-wrap:anywhere}
.catalog-mobile-price strong{font-size:17px;font-weight:750;line-height:1.45;font-variant-numeric:tabular-nums;color:#0369a1}
.catalog-mobile-details{margin-top:9px;font-size:12px;color:#64748b}
.catalog-mobile-details summary{display:inline-flex;align-items:center;min-height:36px;cursor:pointer;color:#0369a1;font-weight:600}
.catalog-mobile-details[open]{padding-bottom:4px}
.catalog-mobile-details>div{padding:3px 0;overflow-wrap:anywhere}
.catalog-no-results{padding:24px!important;white-space:normal}
@media(max-width:800px){
  .catalog-page{min-width:0}
  .catalog-summary{margin-bottom:10px;padding:10px 13px;align-items:flex-start}
  .catalog-summary-count strong{font-size:20px}
  .catalog-summary-count small{flex-basis:100%}
  .catalog-summary-sync{flex-wrap:wrap}
  .catalog-summary-sync small{flex-basis:100%;margin-left:15px}
  .catalog-filter-bar{padding:10px!important;margin-bottom:10px;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px}
  .catalog-search-box{grid-column:1/-1;width:100%}
  .catalog-filter-bar>select{width:100%;min-width:0;font-size:14px}
  .catalog-filter-bar>button{grid-column:1/-1}
  .catalog-table-card{border-radius:14px}
  .catalog-table-card .pager{display:flex;flex-direction:column;gap:10px;padding:12px 14px;align-items:stretch;font-size:12px}
  .catalog-table-card .pager-btns{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:6px}
  .catalog-table-card .pager-btns .btn{padding:8px;min-width:0}
}
@media(max-width:360px){
  .catalog-mobile-item{padding:12px}
  .catalog-mobile-prices{gap:8px}
  .catalog-mobile-price strong{font-size:16px}
  .catalog-filter-bar{grid-template-columns:1fr}
  .catalog-filter-bar>select{grid-column:1/-1}
}
</style>
