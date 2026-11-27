<template>
  <section class="insight-slide">
    <div class="eyebrow"><span></span>{{ eyebrow }}</div>
    <h1>
      {{ title }}<em v-if="suffix"> {{ suffix }}</em>
    </h1>
    <p class="subtitle">{{ subtitle }}</p>

    <template v-if="page.type === 'activity'">
      <div class="stats">
        <div>
          <span>เข้าพบทั้งหมด</span><strong>{{ report.visits.length }}</strong
          ><small>ครั้งในช่วงรายงาน</small>
        </div>
        <div>
          <span>เข้าพบร้านค้าใหม่</span><strong>{{ newCount }}</strong
          ><small>ครั้ง ตามประเภทที่บันทึก</small>
        </div>
        <div>
          <span>เข้าพบร้านค้าเดิม</span><strong>{{ existingCount }}</strong
          ><small>ครั้ง ไม่รวมรายการไม่ระบุ</small>
        </div>
        <div>
          <span>จุดในแผนที่เข้าพบ</span
          ><strong
            >{{ report.plan_summary?.visited_planned_stops ?? 0 }}
            <small>/ {{ report.plan_summary?.planned_stops ?? 0 }}</small></strong
          ><small>{{ report.plan_summary?.planned_stops ? 'อ้างอิงบันทึกที่เชื่อมกับแผน' : 'ยังไม่มีจุดในแผน' }}</small>
        </div>
      </div>
      <div class="two-col">
        <section class="panel">
          <h2>การเข้าพบรายวัน</h2>
          <div v-if="dailyVisits.length" class="rows">
            <div v-for="item in dailyVisits" :key="item.date" class="row">
              <span>{{ dateLabel(item.date) }}</span><b>{{ item.total }} ครั้ง</b>
            </div>
          </div>
          <p v-else class="empty">ยังไม่มีบันทึกเข้าพบในช่วงนี้</p>
        </section>
        <section class="panel">
          <h2>เทียบเป้าหมายช่วงเดียวกัน</h2>
          <div
            v-if="report.target && (Number(report.target.target_amount) > 0 || Number(report.target.target_visits) > 0)"
            class="rows"
          >
            <div v-if="Number(report.target.target_visits) > 0" class="row">
              <span>เข้าพบ / เป้า</span><b>{{ report.visits.length }} / {{ fmt(report.target.target_visits) }} ครั้ง</b>
            </div>
            <div v-if="Number(report.target.target_amount) > 0" class="row">
              <span>ยอดขาย / เป้า</span
              ><b
                >{{ report.sales ? fmt(report.sales.net_before_vat) : 'ยังไม่มีรหัส ERP' }} /
                {{ fmt(report.target.target_amount) }} ฿</b
              >
            </div>
          </div>
          <p v-else class="empty">ยังไม่มีเป้าหมายที่ตั้งตรงกับช่วงและพนักงานที่เลือก</p>
          <p class="footnote">เป้าหมายไม่นำมาแบ่งสัดส่วนตามวันหรือสาย</p>
        </section>
      </div>
    </template>

    <template v-else-if="page.type === 'visit-list'">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>วันที่</th>
              <th>ร้าน / พื้นที่</th>
              <th>ผู้เข้าพบ</th>
              <th>ประเภท</th>
              <th>ผลการเข้าพบ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="visit in page.rows" :key="visit.id">
              <td>{{ dateLabel(visit.visited_at) }}</td>
              <td>
                <b>{{ visit.shop_name || 'ไม่ระบุร้าน' }}</b><small>{{ visit.route_name || 'ยังไม่ระบุสาย' }}</small>
              </td>
              <td>{{ visit.staff_name || 'ไม่ได้ระบุ' }}</td>
              <td>{{ shopType(visit.shop_type) }}</td>
              <td>{{ visitResultLabel(visit.result) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="!page.rows.length" class="empty">ไม่มีบันทึกเข้าพบในช่วงที่เลือก</p>
      </div>
    </template>

    <template v-else-if="page.type === 'issues'">
      <div v-if="page.rows.length" class="cards">
        <article v-for="visit in page.rows" :key="visit.id" class="entry">
          <div class="entry-head">
            <b>{{ visit.shop_name || 'ไม่ระบุร้าน' }}</b><span>{{ dateLabel(visit.visited_at) }}</span>
          </div>
          <p v-if="visit.customer_issues"><strong>ข้อมูล / ปัญหา</strong> {{ visit.customer_issues }}</p>
          <p v-if="visit.customer_feedback"><strong>ความคิดเห็น</strong> {{ visit.customer_feedback }}</p>
        </article>
      </div>
      <div v-else class="empty big">ยังไม่มีการบันทึกปัญหาหรือความคิดเห็นของลูกค้าในช่วงนี้</div>
    </template>

    <template v-else-if="page.type === 'follow-ups'">
      <div v-if="page.rows.length" class="cards">
        <article v-for="item in page.rows" :key="item.key" class="entry">
          <div class="entry-head">
            <b>{{ item.shop_name || 'ไม่ระบุร้าน' }}</b><span :class="{ done: item.status === 'done' }">{{
              taskStatus(item.status)
            }}</span>
          </div>
          <p>{{ item.title }}</p>
          <small>กำหนด {{ dateLabel(item.due_date) }}<template v-if="!item.task_id"> · จากบันทึกเข้าพบ</template></small>
        </article>
      </div>
      <div v-else class="empty big">ยังไม่มีงานติดตามจากการเข้าพบในช่วงนี้</div>
      <p class="footnote">แสดงเฉพาะงานที่เชื่อมกับการเข้าพบในช่วงรายงาน สถานะงานเป็นสถานะปัจจุบัน</p>
    </template>

    <template v-else-if="page.type === 'pipeline'">
      <div v-if="report.pipeline?.length" class="stages">
        <div v-for="stage in report.pipeline" :key="stage.pipeline_stage" class="stage-card">
          <span>{{ stageLabel(stage.pipeline_stage) }}</span><strong>{{ fmt(stage.total) }}</strong
          ><small>ร้าน</small>
        </div>
      </div>
      <div v-else class="empty big">ยังไม่มีข้อมูลร้านใหม่ในขอบเขตที่เลือก</div>
      <p class="footnote">สถานะปัจจุบันของร้านที่สร้างถึงวันสิ้นสุดรายงาน ไม่ใช่จำนวนที่เปลี่ยนสถานะภายในสัปดาห์</p>
    </template>

    <template v-else-if="page.type === 'upcoming'">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>วันที่นัด</th>
              <th>ร้านค้า</th>
              <th>แผน / สาย</th>
              <th>ผู้รับผิดชอบ</th>
              <th>สถานะ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="stop in page.rows" :key="stop.id">
              <td>{{ dateLabel(stop.visit_date) }}</td>
              <td>
                <b>{{ stop.shop_name || 'ไม่ระบุร้าน' }}</b>
              </td>
              <td>{{ stop.route_name || 'ไม่ระบุ' }}</td>
              <td>{{ stop.staff_name || 'ไม่ระบุ' }}</td>
              <td>{{ planStatus(stop.status) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="!page.rows.length" class="empty">ยังไม่มีจุดแวะที่บันทึกใน 7 วันถัดจากช่วงรายงาน</p>
      </div>
    </template>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { fmt } from '../composables/useCrmSession.js';
import { visitResultLabel } from '../utils/visitResult.js';
const props = defineProps({ page: { type: Object, required: true }, report: { type: Object, required: true } });
const titles = {
  activity: ['กิจกรรมการขาย', 'การเข้าพบ', 'ภาพรวมการลงพื้นที่และเป้าหมายที่ตั้งไว้'],
  'visit-list': ['สรุปการลงพื้นที่', 'ลูกค้าที่เข้าพบ', 'รายชื่อร้านค้าและผลการเข้าพบจาก CRM'],
  issues: ['เสียงจากลูกค้า', 'ปัญหาและความคิดเห็น', 'ข้อความตามที่พนักงานบันทึกไว้'],
  'follow-ups': ['งานที่ต้องดำเนินการ', 'ติดตามลูกค้า', 'งานติดตามที่เกิดจากการเข้าพบในช่วงนี้'],
  pipeline: ['โอกาสการขาย', 'สถานะร้านใหม่', 'ภาพรวมสถานะปัจจุบันของลูกค้าใหม่'],
  upcoming: ['แผนงานถัดไป', 'จุดแวะในสัปดาห์ถัดไป', 'แผนที่บันทึกไว้สำหรับ 7 วันหลังช่วงรายงาน'],
};
const eyebrow = computed(() => titles[props.page.type]?.[0] || 'รายงานออกตลาด');
const title = computed(() => titles[props.page.type]?.[1] || 'รายงานออกตลาด');
const suffix = computed(() => (props.page.parts > 1 ? `${props.page.part}/${props.page.parts}` : ''));
const subtitle = computed(() => titles[props.page.type]?.[2] || '');
const newCount = computed(() => props.report.visits.filter((v) => v.shop_type === 'new').length);
const existingCount = computed(() => props.report.visits.filter((v) => v.shop_type === 'existing').length);
const dateLabel = (value) =>
  value
    ? new Date(value.length === 10 ? `${value}T12:00:00` : value).toLocaleDateString('th-TH', {
        day: 'numeric',
        month: 'short',
        year: '2-digit',
      })
    : 'ยังไม่ระบุ';
const dailyVisits = computed(() => {
  const days = new Map();
  for (const visit of props.report.visits) {
    const date = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Bangkok',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date(visit.visited_at));
    days.set(date, (days.get(date) || 0) + 1);
  }
  return [...days].sort(([a], [b]) => a.localeCompare(b)).map(([date, total]) => ({ date, total }));
});
const shopType = (value) => ({ new: 'ร้านใหม่', existing: 'ร้านเดิม' })[value] || 'ไม่ระบุ';
const taskStatus = (value) =>
  ({ done: 'เสร็จแล้ว', open: 'รอติดตาม', pending: 'รอติดตาม', in_progress: 'กำลังดำเนินการ' })[value] ||
  value ||
  'ไม่ได้ระบุสถานะ';
const planStatus = (value) =>
  ({ pending: 'รอเข้าพบ', visited: 'เข้าพบแล้ว', completed: 'เสร็จแล้ว', skipped: 'ข้าม', cancelled: 'ยกเลิก' })[value] ||
  value ||
  'ไม่ระบุ';
const stageLabel = (value) =>
  ({
    new_lead: 'ลูกค้าใหม่',
    visited: 'เข้าพบแล้ว',
    contacted: 'ติดต่อแล้ว',
    interested: 'สนใจ',
    quoted: 'เสนอราคา',
    follow_up: 'ติดตามต่อ',
    erp_creation_requested: 'ขอเปิดรหัส ERP',
    erp_match_pending: 'รอจับคู่ ERP',
    converted: 'แปลงเป็นลูกค้าแล้ว',
    lost: 'ไม่สำเร็จ',
  })[value] ||
  value ||
  'ไม่ระบุ';
</script>

<style scoped>
.insight-slide {
  color: #142849;
}
.eyebrow {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  color: #4263a3;
}
.eyebrow span {
  display: inline-block;
  width: 8px;
  height: 8px;
  background: #466cf0;
  border-radius: 50%;
  margin-right: 8px;
}
.insight-slide h1 {
  font-size: clamp(26px, 3vw, 39px);
  line-height: 1.25;
  margin: 18px 0 7px;
}
.insight-slide h1 em {
  font-style: normal;
  color: #7591c2;
  font-size: 16px;
}
.subtitle {
  font-size: 13px;
  color: #7b8ba1;
  margin: 0 0 24px;
}
.stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
}
.stats > div,
.panel,
.entry,
.stage-card,
.table-wrap {
  background: #fff;
  border: 1px solid #e1eaf6;
  border-radius: 14px;
}
.stats > div {
  padding: 18px;
  display: grid;
  gap: 8px;
  color: #71829b;
  font-size: 12px;
}
.stats strong {
  font-size: 28px;
  color: #284c9d;
}
.stats strong small {
  font-size: 15px;
}
.stats small {
  font-size: 11px;
  color: #93a0b2;
}
.two-col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-top: 15px;
}
.panel {
  padding: 18px;
}
.panel h2 {
  margin: 0 0 12px;
  font-size: 16px;
  color: #263e63;
}
.rows {
  max-height: 250px;
  overflow: auto;
}
.row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
  border-top: 1px solid #edf1f7;
  color: #6a7b93;
  font-size: 13px;
}
.row b {
  color: #31508b;
}
.footnote {
  font-size: 11px;
  color: #9a7b42;
  margin: 14px 0 0;
  line-height: 1.5;
}
.table-wrap {
  overflow: auto;
  max-height: 450px;
}
.table-wrap table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.table-wrap th,
.table-wrap td {
  text-align: left;
  padding: 13px 14px;
  border-bottom: 1px solid #edf1f7;
  vertical-align: top;
}
.table-wrap th {
  background: #f2f6fd;
  color: #788ba8;
  font-size: 11px;
}
.table-wrap td {
  color: #475b78;
}
.table-wrap td b {
  color: #1f3c68;
}
.table-wrap td small {
  display: block;
  color: #94a3b8;
  margin-top: 4px;
}
.cards {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.entry {
  padding: 16px;
  overflow-wrap: anywhere;
}
.entry-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  color: #214071;
}
.entry-head span {
  font-size: 11px;
  color: #9a6a21;
  white-space: nowrap;
}
.entry-head span.done {
  color: #16845f;
}
.entry p {
  font-size: 13px;
  line-height: 1.6;
  margin: 9px 0;
  color: #50647e;
  white-space: pre-wrap;
}
.entry p strong {
  display: block;
  color: #7a8da7;
  font-size: 11px;
}
.entry small {
  color: #8c9db3;
}
.stages {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
}
.stage-card {
  padding: 20px;
  display: grid;
  gap: 8px;
  color: #6a7d96;
}
.stage-card strong {
  font-size: 30px;
  color: #315ee7;
}
.stage-card small {
  color: #9aa8bb;
}
.empty {
  color: #92a1b6;
  font-size: 13px;
  padding: 15px;
}
.empty.big {
  padding: 100px 20px;
  text-align: center;
  background: white;
  border: 1px solid #e1eaf6;
  border-radius: 14px;
}
@media (max-width: 700px) {
  .stats {
    grid-template-columns: repeat(2, 1fr);
  }
  .stats > div {
    padding: 13px;
  }
  .stats strong {
    font-size: 22px;
  }
  .two-col,
  .cards {
    grid-template-columns: 1fr;
  }
  .stages {
    grid-template-columns: repeat(2, 1fr);
  }
  .table-wrap table {
    min-width: 620px;
  }
  .insight-slide h1 {
    font-size: 26px;
  }
  .subtitle {
    font-size: 12px;
  }
}
</style>
