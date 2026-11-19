<template>
  <div ref="presentationRoot" class="presentation">
    <header class="topbar">
      <div class="brand"><span class="brand-icon">P</span><div><b>POPSTAR <em>CONNECT</em></b><small>FIELD PRESENTATION</small></div></div>
      <div class="top-actions">
        <button type="button" class="top-btn" aria-label="ย่อสไลด์" :disabled="zoom <= 0.8" @click="zoom = Math.max(0.8, +(zoom - 0.1).toFixed(1))">−</button>
        <span class="zoom-value">{{ Math.round(zoom * 100) }}%</span>
        <button type="button" class="top-btn" aria-label="ขยายสไลด์" :disabled="zoom >= 1.3" @click="zoom = Math.min(1.3, +(zoom + 0.1).toFixed(1))">+</button>
        <button type="button" class="top-btn fullscreen" @click="toggleFullscreen">{{ fullscreen ? '⤢ ออกจากเต็มจอ' : '⛶ เต็มจอ' }}</button>
        <a href="/reports" class="top-btn exit">← กลับรายงาน</a>
      </div>
    </header>
    <div v-if="loading" class="state">กำลังรวบรวมข้อมูลออกตลาด…</div>
    <div v-else-if="error" class="state"><strong>เปิดรายงานไม่สำเร็จ</strong><p>{{ error }}</p><a href="/reports">กลับไปเลือกข้อมูล</a></div>
    <template v-else-if="report">
      <div v-if="fullscreenHint" class="fullscreen-hint" role="status">{{ fullscreenHint }}</div>
      <div class="context"><span>รายงานออกตลาด</span><span class="context-sep">/</span><span>{{ dateLabel(report.filters.from) }} – {{ dateLabel(report.filters.to) }}</span><span class="context-sep">/</span><span>{{ report.selected_staff?.name || 'พนักงานที่มีสิทธิ์' }}</span></div>
      <div class="stage"><article class="slide" :style="{ zoom }">
        <template v-if="current.type === 'summary'">
          <div class="slide-eyebrow"><span class="dot"></span> สรุปผลการออกตลาด</div>
          <div class="cover-grid">
            <div class="cover-copy"><p class="pretitle">FIELD VISIT REPORT</p><h1>ทุกการเข้าพบ<br /><span>มีเรื่องราว</span></h1><p class="description">สรุปการออกตลาดและยอดซื้อจริงจากข้อมูลที่บันทึกในระบบ พร้อมใช้ในการประชุมทีม</p><div class="cover-pills"><span>📅 {{ dateLabel(report.filters.from) }} – {{ dateLabel(report.filters.to) }}</span><span>👤 {{ report.selected_staff?.name || 'พนักงานที่มีสิทธิ์' }}</span><span>📍 {{ report.filters.route_code ? `สาย ${report.filters.route_code}` : 'ทุกสาย' }}</span></div></div>
            <div class="summary-card"><div class="card-label">ภาพรวมช่วงที่เลือก</div><div class="metric"><span>เข้าพบลูกค้า</span><strong>{{ report.visits.length }} <small>ครั้ง</small></strong></div><div class="metric"><span>ร้านที่เข้าพบ</span><strong>{{ shopCount }} <small>ร้าน</small></strong></div><div class="sales-metric"><span>ยอดซื้อสุทธิก่อน VAT</span><strong>{{ salesValue }}</strong><small>{{ missingCoverage ? 'ข้อมูล ERP อาจยังซิงก์ไม่ถึงช่วงที่เลือก' : 'หักคืนแล้ว · ข้อมูล ERP อาจยังไม่ครบ' }}</small></div></div>
          </div>
          <div class="slide-footer"><span>POPSTAR · สรุปจากข้อมูลจริงในระบบ</span><span>01 / {{ pages.length.toString().padStart(2, '0') }}</span></div>
        </template>
        <template v-else-if="current.type === 'sales'">
          <div class="slide-eyebrow"><span class="dot"></span> ยอดขายจริงรายวัน</div><h1 class="page-title">ยอดซื้อสุทธิ <span>ตามวัน</span></h1><p class="page-sub">ยอดก่อน VAT หลังหักคืน · {{ dateLabel(report.filters.from) }} – {{ dateLabel(report.filters.to) }}</p>
          <div v-if="report.sales && daily.length" class="chart-card"><div class="chart-header"><div><b>ยอดซื้อสุทธิรายวัน</b><small>เอกสารขายและใบคืนที่อยู่ในระบบ</small></div><strong>{{ salesValue }}</strong></div><div class="bars"><div v-for="day in daily" :key="day.sale_date" class="bar-column"><span class="bar-value">{{ fmt(day.net_before_vat) }}</span><div class="bar-track"><div class="bar" :class="{negative:Number(day.net_before_vat)<0}" :style="{height:`${barHeight(day.net_before_vat)}%`}"></div></div><small>{{ shortDate(day.sale_date) }}</small></div></div></div>
          <div v-else class="empty-state">{{ report.sales ? (missingCoverage ? 'ข้อมูล ERP อาจยังซิงก์ไม่ถึงช่วงที่เลือก' : 'ไม่มีบิลขายจริงในช่วงที่เลือก') : 'ยังไม่มีรหัสพนักงาน ERP สำหรับสรุปยอด' }}</div>
          <div class="note">ข้อมูลจาก ERP mirror ล่าสุด {{ dateLabel(report.latest_invoice_at) }} · ยอดอาจยังไม่ครบระหว่างการซิงก์และต้องตรวจเทียบ Bplus</div><div class="slide-footer"><span>ยอดจริงหลังหักคืน · ไม่รวมใบจอง</span><span>02 / {{ pages.length.toString().padStart(2, '0') }}</span></div>
        </template>
        <template v-else-if="['activity', 'visit-list', 'issues', 'follow-ups', 'pipeline', 'upcoming'].includes(current.type)">
          <VisitInsightSlide :page="current" :report="report" />
          <div class="slide-footer"><span>ข้อมูลตามที่บันทึกในระบบ · {{ dateLabel(report.filters.from) }} – {{ dateLabel(report.filters.to) }}</span><span>{{ String(index + 1).padStart(2, '0') }} / {{ String(pages.length).padStart(2, '0') }}</span></div>
        </template>
        <template v-else>
          <div class="slide-eyebrow"><span class="dot"></span> บรรยากาศออกพบลูกค้า</div>
          <div class="visit-heading"><div><p class="pretitle">CUSTOMER VISIT {{ String(current.visitNumber).padStart(2, '0') }}</p><h1 class="page-title">{{ current.visit.shop_name || 'ไม่ได้ระบุร้าน' }}</h1><p class="page-sub">{{ current.visit.staff_name || 'ไม่ได้ระบุพนักงาน' }} · {{ dateLabel(current.visit.visited_at) }} · {{ current.visit.route_name || 'ยังไม่ระบุสาย' }}</p></div><span class="visit-badge">{{ current.visit.shop_type === 'new' ? 'ร้านค้าใหม่' : current.visit.shop_type === 'existing' ? 'ร้านค้าเดิม' : 'ไม่ระบุประเภทร้าน' }}</span></div>
          <div class="visit-grid"><section class="details"><div class="section-title"><span>✦</span><div><b>ข้อมูลจากการเข้าพบ</b><small>คำถามร้านค้าและการติดตาม</small></div></div><dl><template v-for="[label, value] in visitFields(current.visit)" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></template></dl></section><section class="photo-section"><div class="section-title"><span>▧</span><div><b>ภาพร้านค้าที่เข้าเยี่ยม</b><small>ภาพที่บันทึกจากการออกตลาด</small></div></div><div v-if="current.visit.photos?.length" class="photos"><figure v-for="photo in current.visit.photos" :key="photo.id"><img v-if="!failedPhotos.has(photo.id)" :src="`/api/crm/photos/${photo.id}?inline=1`" :alt="`ภาพ ${photo.category}`" @error="failedPhotos.add(photo.id)" /><div v-else class="photo-empty">ไม่สามารถโหลดภาพนี้</div><figcaption>{{ photoLabel(photo.category) }}</figcaption></figure></div><div v-else class="photo-empty"><span>▧</span><b>ยังไม่มีรูปภาพ</b><small>ไม่มีภาพที่บันทึกในการเข้าพบครั้งนี้</small></div></section></div>
          <div class="slide-footer"><span>ข้อมูลจากบันทึกเข้าพบจริง · {{ current.visit.erp_customer_code || 'ลูกค้ายังไม่มีรหัส ERP' }}</span><span>{{ String(index + 1).padStart(2, '0') }} / {{ String(pages.length).padStart(2, '0') }}</span></div>
        </template>
      </article></div>
      <footer class="controls"><div class="progress-info"><span class="live-dot"></span><span>{{ pageLabel(current) }}</span><b>{{ index + 1 }} <i>/</i> {{ pages.length }}</b></div><div class="control-buttons"><button type="button" :disabled="index === 0" @click="previous">← <span>ก่อนหน้า</span></button><button type="button" class="next" :disabled="index === pages.length - 1" @click="next"><span>ถัดไป</span> →</button></div><label class="page-picker"><span>ไปหน้า</span><select v-model.number="index" aria-label="เลือกหน้ารายงาน"><option v-for="(page, i) in pages" :key="i" :value="i">{{ i + 1 }} · {{ pageLabel(page) }}</option></select></label></footer>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { crmGet, fmt } from '../composables/useCrmSession.js';
import { visitFields } from '../utils/visitPresentation.js';
import VisitInsightSlide from '../components/VisitInsightSlide.vue';
const route = useRoute();
const presentationRoot = ref(null), report = ref(null), loading = ref(true), error = ref(''), fullscreenHint = ref(''), index = ref(0), zoom = ref(1), fullscreen = ref(false);
const failedPhotos = ref(new Set());
function paginate(type, rows, size) {
  const groups = [];
  for (let i = 0; i < rows.length; i += size) groups.push(rows.slice(i, i + size));
  if (!groups.length) groups.push([]);
  return groups.map((group, i) => ({ type, rows: group, part: i + 1, parts: groups.length }));
}
const pages = computed(() => {
  if (!report.value) return [];
  const visits = report.value.visits || [];
  const issues = visits.filter((visit) => String(visit.customer_issues || '').trim() || String(visit.customer_feedback || '').trim());
  const taskVisitIds = new Set((report.value.follow_ups || []).map((task) => Number(task.visit_id)));
  const tasks = (report.value.follow_ups || []).map((task) => ({ ...task, task_id: task.id, key: `task-${task.id}` }));
  for (const visit of visits) {
    if (String(visit.follow_up_action || '').trim() && !taskVisitIds.has(Number(visit.id))) {
      tasks.push({ key: `visit-${visit.id}`, shop_name: visit.shop_name, title: visit.follow_up_action, due_date: visit.follow_up_date, status: null });
    }
  }
  return [
    { type: 'summary' }, { type: 'sales' }, { type: 'activity' },
    ...paginate('visit-list', visits, 6),
    ...paginate('issues', issues, 4),
    ...paginate('follow-ups', tasks, 4),
    { type: 'pipeline' },
    ...paginate('upcoming', report.value.upcoming_stops || [], 6),
    ...visits.map((visit, i) => ({ type: 'visit', visit, visitNumber: i + 1 })),
  ];
});
function pageLabel(page) {
  return ({ summary: 'ภาพรวม', sales: 'ยอดขายรายวัน', activity: 'กิจกรรมการขาย', 'visit-list': 'รายชื่อร้านที่เข้าพบ', issues: 'ปัญหาและความคิดเห็น', 'follow-ups': 'งานติดตาม', pipeline: 'สถานะร้านใหม่', upcoming: 'แผนสัปดาห์ถัดไป' }[page?.type] || page?.visit?.shop_name || 'รายละเอียดร้าน');
}
const current = computed(() => pages.value[index.value] || {});
const daily = computed(() => report.value?.daily_sales || []);
const maxSale = computed(() => Math.max(1, ...daily.value.map((d) => Math.abs(Number(d.net_before_vat)))));
const missingCoverage = computed(() => !report.value?.latest_invoice_at || new Date(report.value.latest_invoice_at) < new Date(`${report.value.filters.from}T00:00:00+07:00`));
const salesValue = computed(() => report.value?.sales ? (missingCoverage.value && !daily.value.length ? 'ยังไม่มีข้อมูลช่วงนี้' : `${fmt(report.value.sales.net_before_vat)} ฿`) : 'ยังไม่มีรหัสพนักงาน ERP');
const shopCount = computed(() => new Set((report.value?.visits || []).map((v) => v.erp_customer_code ? `erp:${v.erp_customer_code}` : v.crm_lead_id ? `lead:${v.crm_lead_id}` : `visit:${v.id}`)).size);
const dateLabel = (value) => value ? new Date(value.length === 10 ? `${value}T12:00:00` : value).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' }) : 'ยังไม่มีข้อมูล';
const shortDate = (value) => value ? new Date(`${value}T12:00:00`).toLocaleDateString('th-TH', { day: 'numeric', month: 'short' }) : '';
const barHeight = (value) => Math.max(3, Math.abs(Number(value)) / maxSale.value * 100);
const photoLabel = (category) => ({ storefront: 'หน้าร้าน', interior: 'ภายในร้าน', products: 'สินค้า', competitor: 'คู่แข่ง', customer_discussion: 'พูดคุยกับลูกค้า', other: 'ภาพประกอบ' }[category] || 'ภาพประกอบ');
function previous() { if (index.value > 0) index.value--; }
function next() { if (index.value < pages.value.length - 1) index.value++; }
function onKeydown(event) { if (event.key === 'ArrowRight') { event.preventDefault(); next(); } else if (event.key === 'ArrowLeft') { event.preventDefault(); previous(); } }
function syncFullscreen() { fullscreen.value = !!document.fullscreenElement; }
async function toggleFullscreen() {
  try { fullscreenHint.value = ''; if (document.fullscreenElement) await document.exitFullscreen(); else if (presentationRoot.value?.requestFullscreen) await presentationRoot.value.requestFullscreen(); else fullscreenHint.value = 'เบราว์เซอร์ไม่รองรับเต็มจอ กด F11 เพื่อขยายหน้าต่าง'; }
  catch { fullscreenHint.value = 'เบราว์เซอร์ไม่อนุญาตเต็มจอ กด F11 เพื่อขยายหน้าต่าง'; }
}
onMounted(async () => {
  document.addEventListener('fullscreenchange', syncFullscreen);
  window.addEventListener('keydown', onKeydown);
  try {
    const params = new URLSearchParams();
    for (const key of ['from', 'to', 'staff_id', 'route_code']) if (route.query[key]) params.set(key, String(route.query[key]));
    report.value = await crmGet(`/reports/visit-presentation?${params}`);
  } catch (reason) { error.value = reason?.message || 'ไม่สามารถโหลดข้อมูลได้'; }
  finally { loading.value = false; }
});
onUnmounted(() => { document.removeEventListener('fullscreenchange', syncFullscreen); window.removeEventListener('keydown', onKeydown); });
</script>

<style scoped>
.presentation{position:fixed;inset:0;z-index:2000;background:#0b1528;color:#e5ebf6;display:flex;flex-direction:column;font-family:Tahoma,sans-serif}.topbar{height:70px;padding:0 3%;border-bottom:1px solid #23324a;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-shrink:0}.brand{display:flex;align-items:center;gap:11px;letter-spacing:.05em;font-size:13px}.brand-icon{width:38px;height:38px;border-radius:12px;background:#3764ed;display:grid;place-items:center;font-size:22px;font-weight:800}.brand em{color:#8daaff;font-style:normal}.brand small{display:block;color:#8b9ab1;font-size:9px;margin-top:4px}.top-actions{display:flex;align-items:center;gap:8px}.top-btn{display:inline-grid;place-items:center;min-width:35px;height:35px;padding:0 10px;border:1px solid #34445d;border-radius:9px;background:#17263b;color:#e8eef9;text-decoration:none;cursor:pointer;font:inherit;font-size:12px}.top-btn:disabled{opacity:.35}.zoom-value{min-width:42px;text-align:center;color:#abb8ca;font-size:12px}.exit{background:transparent}.context{padding:17px 4% 0;color:#93a2b8;font-size:12px;display:flex;gap:10px;flex-wrap:wrap}.context-sep{color:#43536c}.stage{flex:1;min-height:0;overflow:auto;padding:20px 4%;display:flex;align-items:flex-start;justify-content:center}.slide{position:relative;width:min(1200px,100%);min-height:min(660px,100%);background:#f8faff;color:#142849;border-radius:20px;padding:clamp(22px,4vw,48px);box-shadow:0 20px 60px #0005;overflow:auto}.slide:before{content:'';position:absolute;inset:0 0 auto;height:7px;background:linear-gradient(90deg,#3361ec,#87a9ff)}.slide-eyebrow{font-size:11px;font-weight:800;letter-spacing:.08em;color:#4263a3}.dot,.live-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#466cf0;margin-right:8px}.cover-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:6%;align-items:center;min-height:480px}.pretitle{font-size:11px;font-weight:800;letter-spacing:.14em;color:#5471a7;margin:0 0 12px}.cover-copy h1{font-size:clamp(38px,5vw,64px);line-height:1.18;letter-spacing:-.04em;margin:0 0 18px}.cover-copy h1 span,.page-title span{color:#365fe5}.description{max-width:490px;color:#718096;font-size:16px;line-height:1.8}.cover-pills{display:flex;gap:8px;flex-wrap:wrap;margin-top:26px}.cover-pills span{background:white;color:#435775;border:1px solid #e0e9f6;border-radius:10px;padding:10px 12px;font-size:12px}.summary-card{background:white;border:1px solid #e2eaf6;border-radius:18px;padding:26px;box-shadow:0 15px 36px #183d7910}.card-label{font-weight:700;color:#6d7f99;font-size:12px;margin-bottom:12px}.metric{display:flex;align-items:center;justify-content:space-between;padding:16px 0;border-bottom:1px solid #edf1f7;font-size:14px;color:#536781}.metric strong{font-size:26px;color:#183a71}.metric strong small{font-size:12px;color:#8392a9}.sales-metric{margin-top:20px;background:#eef3ff;border-radius:14px;padding:18px;display:grid;gap:7px;color:#5b6d8c;font-size:12px}.sales-metric strong{font-size:25px;color:#315ee7}.sales-metric small{color:#8997ad}.slide-footer{display:flex;justify-content:space-between;gap:10px;border-top:1px solid #e6edf7;margin-top:22px;padding-top:15px;font-size:11px;color:#9aa9be}.page-title{font-size:clamp(25px,3vw,39px);line-height:1.25;margin:18px 0 6px;color:#142849}.page-sub{color:#788aa4;font-size:13px;margin:0 0 22px}.chart-card,.details,.photo-section{background:#fff;border:1px solid #e1eaf6;border-radius:16px;padding:20px}.chart-header{display:flex;justify-content:space-between;gap:15px;color:#25416d}.chart-header small,.section-title small{display:block;color:#94a2b7;font-size:11px;margin-top:5px}.chart-header strong{color:#315ee7;font-size:20px}.bars{height:320px;display:flex;align-items:end;gap:12px;overflow-x:auto;padding:28px 0 0}.bar-column{min-width:52px;flex:1;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:end;gap:8px}.bar-track{height:220px;width:100%;display:flex;align-items:end;justify-content:center;border-bottom:1px solid #d9e3f1}.bar{width:27px;min-height:3px;background:linear-gradient(#7394fa,#3763e9);border-radius:6px 6px 0 0}.bar.negative{background:#e27a78}.bar-value{font-size:10px;color:#617798;white-space:nowrap}.bar-column small{font-size:10px;color:#8091a8;white-space:nowrap}.note{margin-top:14px;color:#a46d18;font-size:11px}.empty-state{padding:100px 20px;text-align:center;color:#8695ab;background:#fff;border:1px solid #e1eaf6;border-radius:16px}.visit-heading{display:flex;justify-content:space-between;gap:12px;align-items:center}.visit-heading .page-title{margin:0 0 6px}.visit-heading .pretitle{margin:14px 0 8px}.visit-badge{background:#e9f8f0;color:#24805c;padding:9px 12px;border-radius:20px;font-size:12px;white-space:nowrap}.visit-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:18px}.section-title{display:flex;align-items:center;gap:10px;margin-bottom:14px;color:#253e65}.section-title>span{width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:#edf3ff;color:#4168e9}.details dl{display:grid;grid-template-columns:1fr 1.5fr;margin:0;font-size:12px;line-height:1.55}.details dt,.details dd{margin:0;border-top:1px solid #edf1f7;padding:9px 4px;overflow-wrap:anywhere}.details dt{color:#8090a6}.details dd{color:#273b57}.photos{display:grid;gap:10px}.photos figure{margin:0;border-radius:12px;overflow:hidden;background:#f2f5fa}.photos img{display:block;width:100%;height:160px;object-fit:contain}.photos figcaption{padding:7px 10px;color:#8695ab;font-size:11px}.photo-empty{height:230px;border:1px dashed #cfdaea;border-radius:12px;background:#f8faff;display:grid;align-content:center;justify-items:center;gap:8px;color:#8a9bb1}.photo-empty span{font-size:32px}.photo-empty small{font-size:11px}.controls{padding:14px 4% 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-shrink:0;color:#9eabc0;font-size:12px}.live-dot{background:#5bd0a0}.progress-info b{margin-left:12px;color:#fff}.progress-info i{font-style:normal;color:#60718b}.control-buttons{display:flex;gap:10px}.control-buttons button{padding:10px 20px;border-radius:10px;border:1px solid #34445d;background:#17263b;color:#e5ebf6;cursor:pointer}.control-buttons button:disabled{opacity:.35}.control-buttons .next{background:#3561e9;border-color:#3561e9;color:#fff}.state{flex:1;display:grid;place-content:center;text-align:center;color:#cbd5e1}.state a{color:#9bb3ff}.state p{max-width:560px}.key-hint{color:#657891}@media(max-width:700px){.topbar{height:auto;min-height:62px;padding:10px 12px}.brand small,.brand b,.zoom-value,.key-hint{display:none}.top-actions{gap:4px}.top-btn{font-size:11px;padding:0 7px}.context{padding:12px 12px 0}.stage{padding:12px}.slide{min-height:100%;padding:24px 18px;border-radius:14px}.cover-grid,.visit-grid{grid-template-columns:1fr;min-height:auto}.cover-grid{gap:20px;padding-top:35px}.cover-copy h1{font-size:37px}.description{font-size:13px}.summary-card{padding:16px}.metric{padding:10px 0}.sales-metric{margin-top:12px;padding:12px}.sales-metric strong{font-size:20px}.controls{padding:8px 12px 14px}.progress-info span:not(.live-dot){display:none}.control-buttons button{padding:10px 12px}.bars{height:260px}.bar-track{height:160px}.chart-card,.details,.photo-section{padding:14px}.visit-grid{gap:12px}.visit-heading{align-items:start}.details dl{grid-template-columns:1fr 1.35fr}.photos img{height:150px}}
.topbar{background:#0b1528}.topbar .brand b{color:#e8eef9}.topbar .top-btn{color:#e8eef9}.fullscreen-hint{background:#fff4d6;color:#7a4a00;text-align:center;padding:8px;font-size:12px}.page-picker{display:flex;align-items:center;gap:8px;color:#9eabc0;font-size:12px}.page-picker select{max-width:210px;background:#17263b;border:1px solid #34445d;border-radius:8px;color:#e8eef9;padding:8px;font-size:12px}@media(max-width:700px){.page-picker{gap:3px}.page-picker span{display:none}.page-picker select{max-width:100px;padding:7px 4px}.controls{gap:4px}}
</style>
