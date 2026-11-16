<template>
  <div>
    <PageHeader
      title="แผนที่ร้าน"
      subtitle="ดูร้านบนแผนที่ และปักหมุดตำแหน่งร้านด้วย GPS"
    >
      <template #primary>
        <button type="button" class="btn primary" @click="getGps">📍 รับพิกัด GPS ปัจจุบัน</button>
      </template>
    </PageHeader>

    <div class="split">
      <div class="card panel-pad">
        <CustomerMap
          :pins="pins || []"
          :select-mode="true"
          :selected-pin-id="selectedPin?.id"
          :picked-location="activeLoc"
          height="460px"
          @picked="manualLoc = $event"
          @select="selectedPin = $event"
        />
        <div class="map-foot">
          <span>● ลูกค้า ERP &nbsp; ● Lead อนุมัติ &nbsp; ● Lead รออนุมัติ ({{ (pins || []).length }} จุด · กดที่หมุดเพื่อดูรูปและข้อมูลร้าน)</span>
          <span v-if="activeLoc" class="tag blue">จุดที่เลือก: {{ activeLoc.latitude }}, {{ activeLoc.longitude }}</span>
        </div>
      </div>

      <div class="card panel-pad">
        <h3 style="margin-top:0">ผูกหมุดร้านค้า</h3>
        <div class="formfield">
          <label>ประเภท</label>
          <select v-model="subjectKind" @change="subjectId = ''">
            <option value="lead">ร้านค้าใหม่ (CRM Lead)</option>
            <option value="erp">ลูกค้า ERP</option>
          </select>
        </div>
        <div class="formfield">
          <label>เลือกร้านค้า</label>
          <select v-model="subjectId">
            <option value="">-- เลือกร้านค้า --</option>
            <option v-for="opt in options" :key="opt.id" :value="opt.id">{{ opt.label }}</option>
          </select>
        </div>
        <div v-if="activeLoc" class="notice">
          พิกัดพร้อมบันทึก: <b>{{ activeLoc.latitude }}, {{ activeLoc.longitude }}</b>
          <span v-if="activeLoc.accuracy_m != null"> (±{{ activeLoc.accuracy_m }}ม.)</span>
        </div>
        <div style="display:grid;gap:8px">
          <button type="button" class="btn primary" :disabled="saving || !subjectId || !activeLoc" @click="savePin">
            {{ saving ? 'กำลังบันทึกหมุด...' : '📌 บันทึกหมุดร้านค้านี้' }}
          </button>
          <button
            v-if="subjectId"
            type="button"
            class="btn light"
            @click="openVisitWizard({ kind: subjectKind, id: Number(subjectId) })"
          >
            📸 บันทึกเข้าพบร้านค้านี้
          </button>
        </div>
      </div>
    </div>

    <PinDetailModal :pin="selectedPin" @close="selectedPin = null" />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import CustomerMap from '../CustomerMap.vue';
import PinDetailModal from '../components/PinDetailModal.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, invalidateCrmData, openVisitWizard } from '../composables/useCrmSession.js';

const subjectKind = ref('lead');
const subjectId = ref('');
const gpsLoc = ref(null);
const manualLoc = ref(null);
const selectedPin = ref(null);
const saving = ref(false);

const { data: pins, refetch: refetchPins } = useQuery({
  queryKey: ['crm', 'pins'],
  queryFn: ({ signal }) => crmGet('/pins', { signal }),
});

const { data: leads } = useQuery({
  queryKey: ['crm', 'map-leads'],
  queryFn: ({ signal }) => crmGet('/leads', { signal }),
});

const { data: customers } = useQuery({
  queryKey: ['crm', 'map-customers'],
  queryFn: ({ signal }) => crmGet('/customers', { signal }),
});

const options = computed(() =>
  subjectKind.value === 'erp'
    ? (customers.value || []).map((c) => ({ id: String(c.id), label: `[${c.erp_customer_code}] ${c.customer_name}` }))
    : (leads.value || []).map((l) => ({ id: String(l.id), label: `[LEAD-${l.id}] ${l.shop_name}` }))
);

const activeLoc = computed(() => manualLoc.value || gpsLoc.value);

function getGps() {
  if (!navigator.geolocation) {
    toast.warning('เบราว์เซอร์ไม่รองรับ GPS');
    return;
  }
  navigator.geolocation.getCurrentPosition(
    (p) => {
      manualLoc.value = null;
      gpsLoc.value = {
        latitude: Number(p.coords.latitude.toFixed(7)),
        longitude: Number(p.coords.longitude.toFixed(7)),
        accuracy_m: Math.round(p.coords.accuracy),
      };
      toast.success('รับพิกัด GPS แล้ว');
    },
    (err) => toast.error('ไม่สามารถรับพิกัด GPS', err.message),
    { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
  );
}

async function savePin() {
  if (!subjectId.value || !activeLoc.value) return;
  saving.value = true;
  try {
    await crmMutate('/pins', 'POST', {
      ...(subjectKind.value === 'erp' ? { erp_customer_id: Number(subjectId.value) } : { crm_lead_id: Number(subjectId.value) }),
      latitude: activeLoc.value.latitude,
      longitude: activeLoc.value.longitude,
      accuracy_m: activeLoc.value.accuracy_m ?? null,
      source: manualLoc.value ? 'manual' : 'gps',
    });
    manualLoc.value = null;
    toast.success('บันทึกหมุด GPS เรียบร้อยแล้ว');
    await refetchPins();
    invalidateCrmData(['dashboard', 'leads', 'customers', 'pins']);
  } catch (err) {
    toast.error('บันทึกหมุดไม่สำเร็จ', err);
  } finally {
    saving.value = false;
  }
}
</script>
