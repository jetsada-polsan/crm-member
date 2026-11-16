<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
  pins: { type: Array, default: () => [] },
  selectMode: { type: Boolean, default: false },
  height: { type: String, default: '270px' }
});
const emit = defineEmits(['picked']);
const element = ref(null);
let map, group, resizeObs;

const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

const markerColor = p => {
  if (p.erp_customer_id) return '#3268ec';
  if (p.converted_erp_customer_id) return '#7c3aed';
  if (p.approval_status === 'approved') return '#0cab7e';
  return '#ed9b2d';
};

const redraw = () => {
  if (!map || !group) return;
  group.clearLayers();
  for (const p of props.pins) {
    const lat = Number(p.latitude);
    const lng = Number(p.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;
    const color = markerColor(p);
    const label = esc(p.customer_name || p.shop_name || 'ร้านค้า');
    const kind = p.erp_customer_id
      ? `ลูกค้า ERP (${esc(p.erp_customer_code || '')})`
      : `Lead ใหม่ (${esc(p.approval_status || 'pending')})`;
    const gmap = `https://www.google.com/maps?q=${lat},${lng}`;
    L.circleMarker([lat, lng], { radius: 8, color, fillColor: color, fillOpacity: 0.88 })
      .addTo(group)
      .bindPopup(`<strong>${label}</strong><br><small>${kind}</small><br><a href="${gmap}" target="_blank" rel="noopener">เปิด Google Maps</a>`);
  }
};

onMounted(() => {
  map = L.map(element.value).setView([15.2287, 104.8564], 10);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap contributors'
  }).addTo(map);
  group = L.layerGroup().addTo(map);
  map.on('click', e => {
    if (props.selectMode) {
      emit('picked', {
        latitude: Number(e.latlng.lat.toFixed(7)),
        longitude: Number(e.latlng.lng.toFixed(7))
      });
    }
  });
  redraw();
  setTimeout(() => map?.invalidateSize(), 60);
  if (typeof ResizeObserver !== 'undefined' && element.value) {
    resizeObs = new ResizeObserver(() => map?.invalidateSize());
    resizeObs.observe(element.value);
  }
});

watch(() => props.pins, redraw, { deep: true });
onBeforeUnmount(() => {
  resizeObs?.disconnect();
  map?.remove();
});
</script>

<template>
  <div ref="element" :style="{ height: props.height, borderRadius: '12px', overflow: 'hidden', border: '1px solid #e6ebf2' }"></div>
</template>
