<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { pinCodeLabel, pinDisplayName, pinTypeMeta } from './utils/pinPresentation.js';

const props = defineProps({
  pins: { type: Array, default: () => [] },
  selectMode: { type: Boolean, default: false },
  height: { type: String, default: '360px' },
  selectedPinId: { type: [String, Number], default: null },
  autoFit: { type: Boolean, default: true },
  pickedLocation: { type: Object, default: null },
});

const emit = defineEmits(['picked', 'select']);
const element = ref(null);
let map;
let group;
let pickedMarker;
let resizeObs;
const markerCoordsById = new Map();
let lastFitSignature = '';

const esc = (s) =>
  String(s ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

function computeMarkerPositions(pins) {
  const buckets = new Map();
  const valid = [];

  for (const p of pins) {
    const lat = Number(p.latitude);
    const lng = Number(p.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;
    const key = `${lat.toFixed(4)}:${lng.toFixed(4)}`;
    if (!buckets.has(key)) buckets.set(key, []);
    const item = { pin: p, lat, lng, key };
    buckets.get(key).push(item);
    valid.push(item);
  }

  return valid.map((item) => {
    const groupList = buckets.get(item.key);
    if (!groupList || groupList.length <= 1) {
      return { ...item, displayLat: item.lat, displayLng: item.lng, overlapped: false };
    }
    const idx = groupList.indexOf(item);
    const angle = (2 * Math.PI * idx) / groupList.length - Math.PI / 4;
    const radius = 0.00028;
    return {
      ...item,
      displayLat: item.lat + Math.sin(angle) * radius,
      displayLng: item.lng + Math.cos(angle) * radius * 1.15,
      overlapped: true,
    };
  });
}

function makeMarkerIcon(pin, isSelected) {
  const meta = pinTypeMeta(pin);
  const photoCount = Number(pin.photo_count || 0);
  const selectedClass = isSelected ? ' is-selected' : '';
  const badgeHtml = photoCount > 0 ? `<span class="cm-pin-badge">📷 ${photoCount}</span>` : '';
  return L.divIcon({
    className: `cm-pin-wrap${selectedClass}`,
    html: `<div class="cm-pin" style="--pin-color:${meta.color}"><span>🏪</span></div>${badgeHtml}`,
    iconSize: [36, 40],
    iconAnchor: [18, 22],
  });
}

function redraw(fit = false) {
  if (!map || !group) return;
  group.clearLayers();
  markerCoordsById.clear();

  const positioned = computeMarkerPositions(props.pins);
  const boundsPoints = [];

  for (const item of positioned) {
    const { pin, displayLat, displayLng } = item;
    const isSelected = props.selectedPinId != null && String(pin.id) === String(props.selectedPinId);
    const label = esc(pinDisplayName(pin));
    const code = esc(pinCodeLabel(pin));
    const photoCount = Number(pin.photo_count || 0);
    const tooltipHtml = `<strong>${label}</strong>${code ? `<br><span>${code}</span>` : ''}${photoCount > 0 ? ` · 📷 ${photoCount} รูป` : ''}`;

    const marker = L.marker([displayLat, displayLng], {
      icon: makeMarkerIcon(pin, isSelected),
      zIndexOffset: isSelected ? 600 : 100,
    }).addTo(group);

    marker.bindTooltip(tooltipHtml, {
      direction: 'top',
      offset: [0, -18],
      opacity: 0.96,
      className: 'cm-tooltip',
    });

    marker.on('click', () => {
      emit('select', pin);
    });

    markerCoordsById.set(String(pin.id), [displayLat, displayLng]);
    boundsPoints.push([displayLat, displayLng]);
  }

  const signature = positioned.map((x) => String(x.pin.id)).join(',');
  if (props.autoFit && boundsPoints.length && (fit || signature !== lastFitSignature)) {
    lastFitSignature = signature;
    if (boundsPoints.length === 1) {
      map.setView(boundsPoints[0], Math.max(map.getZoom(), 15), { animate: false });
    } else {
      map.fitBounds(L.latLngBounds(boundsPoints), { padding: [48, 48], maxZoom: 15, animate: false });
    }
  }
}

function redrawPicked() {
  if (!map) return;
  if (pickedMarker) {
    map.removeLayer(pickedMarker);
    pickedMarker = null;
  }
  const lat = Number(props.pickedLocation?.latitude);
  const lng = Number(props.pickedLocation?.longitude);
  if (Number.isFinite(lat) && Number.isFinite(lng)) {
    pickedMarker = L.circleMarker([lat, lng], {
      radius: 9,
      color: '#ffffff',
      weight: 3,
      fillColor: '#0f9d7a',
      fillOpacity: 0.95,
    })
      .addTo(map)
      .bindTooltip('ตำแหน่งที่เลือกใหม่', { direction: 'top' });
  }
}

onMounted(() => {
  map = L.map(element.value, { zoomControl: true }).setView([15.2287, 104.8564], 10);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap contributors',
  }).addTo(map);
  group = L.layerGroup().addTo(map);
  map.on('click', (e) => {
    if (props.selectMode) {
      emit('picked', {
        latitude: Number(e.latlng.lat.toFixed(7)),
        longitude: Number(e.latlng.lng.toFixed(7)),
      });
    }
  });
  redraw(true);
  redrawPicked();
  setTimeout(() => map?.invalidateSize(), 60);
  if (typeof ResizeObserver !== 'undefined' && element.value) {
    resizeObs = new ResizeObserver(() => map?.invalidateSize());
    resizeObs.observe(element.value);
  }
});

watch(() => props.pins, () => redraw(false), { deep: true });
watch(
  () => props.selectedPinId,
  (id) => {
    redraw(false);
    if (map && id != null) {
      const coords = markerCoordsById.get(String(id));
      if (coords) {
        map.panTo(coords, { animate: true });
      }
    }
  }
);
watch(() => props.pickedLocation, redrawPicked, { deep: true });

onBeforeUnmount(() => {
  resizeObs?.disconnect();
  map?.remove();
});
</script>

<template>
  <div ref="element" class="cm-canvas" :style="{ height: props.height }"></div>
</template>

<style scoped>
.cm-canvas {
  border-radius: 14px;
  overflow: hidden;
  border: 1px solid #dbe3ef;
  background: #eaf0f7;
}

:deep(.cm-pin-wrap) {
  background: transparent;
  border: 0;
  position: relative;
}

:deep(.cm-pin) {
  width: 34px;
  height: 34px;
  border-radius: 50% 50% 50% 6px;
  transform: rotate(-45deg);
  background: var(--pin-color, #2f63e8);
  border: 3px solid #ffffff;
  box-shadow: 0 6px 14px rgba(15, 26, 51, 0.28);
  display: grid;
  place-items: center;
  transition: transform 0.16s ease, box-shadow 0.16s ease;
}

:deep(.cm-pin span) {
  transform: rotate(45deg);
  font-size: 15px;
  line-height: 1;
}

:deep(.cm-pin-wrap.is-selected .cm-pin) {
  transform: rotate(-45deg) scale(1.18);
  box-shadow: 0 0 0 6px rgba(47, 99, 232, 0.25), 0 8px 18px rgba(15, 26, 51, 0.32);
}

:deep(.cm-pin-badge) {
  position: absolute;
  top: -8px;
  right: -12px;
  background: #0f1a33;
  color: #ffffff;
  border: 2px solid #ffffff;
  border-radius: 99px;
  padding: 1px 6px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.3;
  white-space: nowrap;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.25);
}

:deep(.cm-tooltip) {
  font-family: var(--font);
  font-size: 12.5px;
  border-radius: 10px;
  border: 1px solid var(--border);
  padding: 6px 10px;
  box-shadow: 0 8px 20px rgba(15, 26, 51, 0.14);
}
</style>
