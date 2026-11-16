<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">POPSTAR CONNECT · สมาชิก</div>
        <h2>ภาพรวมสมาชิก</h2>
        <p>สถานะบัญชี ยอดซื้อ และแต้มสะสมของคุณ</p>
      </div>
      <div class="head-actions">
        <RouterLink to="/binding" class="btn light">🔗 จัดการยืนยันร้านค้า &amp; LINE</RouterLink>
        <RouterLink to="/rewards" class="btn primary">🎁 แลกของรางวัล</RouterLink>
      </div>
    </div>

    <!-- Handoff Stepper + Identity Status Banner -->
    <div class="card panel-pad" style="margin-bottom:18px">
      <div class="stepper" style="margin-bottom:12px">
        <div class="step-pill done">1. สมัครบัญชี Member</div>
        <div :class="['step-pill', me?.line_binding ? 'done' : 'active']">
          2. ผูก LINE OA ({{ me?.line_binding ? 'ผูกแล้ว' : 'ยังไม่ผูก' }})
        </div>
        <div :class="['step-pill', me?.erp_link?.erp_status === 'linked' ? 'done' : 'active']">
          3. รหัสลูกค้า ERP ({{ me?.erp_link?.erp_customer_code || 'รอเชื่อม ERP' }})
        </div>
        <div :class="['step-pill', isMemberVerified ? 'done' : 'active']">
          4. ยืนยันเจ้าของบัญชี ({{ isMemberVerified ? 'ยืนยันแล้ว' : 'รอตรวจสอบสิทธิ์' }})
        </div>
      </div>

      <div v-if="!isMemberVerified" class="notice" style="margin-bottom:0">
        <strong>บัญชียังไม่ปลดล็อกข้อมูลการเงิน ERP:</strong>
        การจับคู่จากเบอร์โทรศัพท์อย่างเดียวจะไม่อนุมัติสิทธิ์อัตโนมัติ —
        กรุณาส่งคำขอยืนยันในหน้า <RouterLink to="/binding"><b>ผูกบัญชี LINE &amp; ร้านค้า ERP</b></RouterLink>
        เพื่อรอทีม Popstar ตรวจสอบความเป็นเจ้าของร้านค้า
      </div>
    </div>

    <!-- Digital Member Card & KPI Grid -->
    <div class="dash-grid" style="margin-bottom:18px">
      <div class="digital-member-card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
          <span class="small" style="opacity:.85">บัตรสมาชิก POPSTAR Connect</span>
          <span class="tag" style="background:rgba(255,255,255,.18);color:#fff">
            {{ isMemberVerified ? '✓ Verified Member' : 'รอการยืนยันสิทธิ์' }}
          </span>
        </div>
        <h2 style="margin:8px 0 4px;font-size:22px">{{ me?.erp_link?.erp_customer_name || me?.name }}</h2>
        <div class="small" style="opacity:.85">
          Member #{{ me?.id }} · {{ me?.phone || me?.email || '-' }}
          <span v-if="me?.erp_link?.erp_customer_code"> · ERP {{ me.erp_link.erp_customer_code }}</span>
        </div>
        <div style="margin-top:18px;display:flex;gap:18px;flex-wrap:wrap">
          <div>
            <div class="small" style="opacity:.75">คะแนนสะสมในพอร์ทัล</div>
            <strong style="font-size:24px">{{ isMemberVerified ? fmt(pointsData?.balance || 0) : '🔒 รอยืนยัน' }}</strong>
          </div>
          <div v-if="isMemberVerified && pointsData?.erp_point_balance != null">
            <div class="small" style="opacity:.75">คะแนนในระบบ Bplus</div>
            <strong style="font-size:24px">{{ fmt(pointsData.erp_point_balance) }}</strong>
          </div>
        </div>
      </div>

      <div class="kpis" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:0">
        <div class="card kpi">
          <div class="kpi-top">
            <div class="kpi-icon">🧾</div>
            <span class="tag blue">ERP</span>
          </div>
          <div class="kpi-label">บิลขายจริงที่เชื่อมแล้ว</div>
          <div class="kpi-value">{{ isMemberVerified ? fmt(me?.purchase_summary?.sale_invoice_count || 0) : '—' }}</div>
          <div v-if="isMemberVerified" class="kpi-foot">
            ซื้อ {{ fmt(me?.purchase_summary?.gross_purchase_before_vat) }} ฿ · คืน {{ fmt(me?.purchase_summary?.return_amount_before_vat) }} ฿
          </div>
          <div v-if="isMemberVerified" class="kpi-foot">
            บิลขาย {{ fmt(me?.purchase_summary?.sale_invoice_count) }} · ใบคืน {{ fmt(me?.purchase_summary?.return_count) }} · ล่าสุด {{ me?.purchase_summary?.last_purchase_at ? new Date(me.purchase_summary.last_purchase_at).toLocaleDateString('th-TH') : '—' }}
          </div>
          <div class="kpi-foot">{{ isMemberVerified ? `ยอดรวม ฿${fmt(totalNetSales)}` : 'รอปลดล็อกสิทธิ์ ERP' }}</div>
        </div>

        <div class="card kpi">
          <div class="kpi-top">
            <div class="kpi-icon green">💬</div>
            <span :class="['tag', me?.line_binding ? 'green' : 'gray']">
              {{ me?.line_binding ? 'ผูกแล้ว' : 'ยังไม่ผูก' }}
            </span>
          </div>
          <div class="kpi-label">LINE Official Account</div>
          <div class="kpi-value" style="font-size:15px;padding-top:6px">
            {{ me?.line_binding?.line_display_name || (me?.line_binding ? 'เชื่อมต่อแล้ว' : 'ยังไม่เชื่อม LINE') }}
          </div>
          <div class="kpi-foot">รับแจ้งเตือนบิลและแต้มทาง LINE</div>
        </div>
      </div>
    </div>

    <div v-if="isMemberVerified" class="middle">
      <div class="card panel-pad">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
          <h3 style="margin:0">ประวัติยอดซื้อล่าสุด (Bplus Mirror)</h3>
          <RouterLink to="/invoices" class="small">ดูทั้งหมด →</RouterLink>
        </div>
        <div v-if="loadingInv"><SkeletonBlock variant="list" :rows="4" /></div>
        <div v-else-if="!(invoices || []).length" class="placeholder">ยังไม่มีบิลขายที่ผ่านเงื่อนไข</div>
        <div v-else>
          <div v-for="inv in (invoices || []).slice(0, 5)" :key="inv.invoice_no" class="minirow">
            <div>
              <strong>{{ inv.invoice_no }}</strong>
              <div class="small muted">
                {{ inv.sold_at }} · {{ inv.doc_category === 'return' ? 'ใบลดหนี้' : `ใบขาย (${inv.doc_type})` }}
              </div>
            </div>
            <strong :style="{ color: inv.doc_category === 'return' ? 'var(--red)' : 'var(--ink)' }">
              ฿{{ fmt(inv.net_amount) }}
            </strong>
          </div>
        </div>
      </div>

      <div class="card panel-pad">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
          <h3 style="margin:0">ความเคลื่อนไหวคะแนนล่าสุด</h3>
          <RouterLink to="/rewards" class="small">แลกรางวัล →</RouterLink>
        </div>
        <div v-if="loadingPts"><SkeletonBlock variant="list" :rows="4" /></div>
        <div v-else-if="!(pointsData?.ledger || []).length" class="placeholder">ยังไม่มีประวัติคะแนนใน Ledger</div>
        <div v-else>
          <div v-for="row in (pointsData?.ledger || []).slice(0, 5)" :key="row.id" class="minirow">
            <div>
              <strong>{{ row.reason || row.event_key }}</strong>
              <div class="small muted">{{ row.created_at }}</div>
            </div>
            <span :class="['tag', Number(row.points_delta) >= 0 ? 'green' : 'orange']">
              {{ Number(row.points_delta) >= 0 ? '+' : '' }}{{ fmt(row.points_delta) }} คะแนน
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { fmt, isMemberVerified, me, memberGet } from '../composables/useMemberSession.js';

const { data: invoices, isLoading: loadingInv } = useQuery({
  queryKey: ['member', 'invoices'],
  queryFn: ({ signal }) => memberGet('/invoices', { signal }),
  enabled: isMemberVerified,
});

const { data: pointsData, isLoading: loadingPts } = useQuery({
  queryKey: ['member', 'points'],
  queryFn: ({ signal }) => memberGet('/points', { signal }),
  enabled: isMemberVerified,
});

const totalNetSales = computed(() => Number(me.value?.purchase_summary?.net_purchase_before_vat || 0));
</script>
