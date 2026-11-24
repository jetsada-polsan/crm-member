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
  pickingHint: { type: String, default: '' },
});

const emit = defineEmits(['picked', 'select']);
const element = ref(null);
const activeTileKey = ref('groad');
const currentZoom = ref(10);

const TILE_LAYERS = {
  groad: {
    label: '🌏 แผนที่ถนน (ไทย)',
    url: 'https://mt{s}.google.com/vt/lyrs=m&hl=th&x={x}&y={y}&z={z}',
    options: { subdomains: ['0', '1', '2', '3'], maxZoom: 20, attribution: '© Google Maps' },
  },
  gsat: {
    label: '🛰️ ดาวเทียม (ไทย)',
    url: 'https://mt{s}.google.com/vt/lyrs=y&hl=th&x={x}&y={y}&z={z}',
    options: { subdomains: ['0', '1', '2', '3'], maxZoom: 20, attribution: '© Google Maps' },
  },
  gterrain: {
    label: '⛰️ ภูมิประเทศ',
    url: 'https://mt{s}.google.com/vt/lyrs=p&hl=th&x={x}&y={y}&z={z}',
    options: { subdomains: ['0', '1', '2', '3'], maxZoom: 18, attribution: '© Google Maps' },
  },
  osm: {
    label: '🗺️ OSM',
    url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    options: { maxZoom: 19, attribution: '© OpenStreetMap' },
  },
};

let map;
let tileLayer;
let group;
let pickedMarker;
let resizeObs;
const markerCoordsById = new Map();
let lastFitSignature = '';

const esc = (s) =>
  String(s ?? '').replace(
    /[&<>"']/g,
    (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]
  );

function extractValidPins(pins) {
  const valid = [];
  for (const p of pins) {
    if (p.latitude == null || p.longitude == null || p.latitude === '' || p.longitude === '') continue;
    const lat = Number(p.latitude);
    const lng = Number(p.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) continue;
    valid.push({ pin: p, lat, lng });
  }
  return valid;
}

function fitMapToValidPins(valid, forceFit) {
  const signature = valid.map((v) => String(v.pin.id)).join(',');
  if (!valid.length) {
    lastFitSignature = '';
    return;
  }
  if (!props.autoFit && !forceFit) {
    return;
  }
  if (!forceFit && signature === lastFitSignature) {
    return;
  }
  lastFitSignature = signature;
  const pts = valid.map((v) => [v.lat, v.lng]);
  if (pts.length === 1) {
    map.setView(pts[0], Math.max(map.getZoom(), 15), { animate: forceFit });
  } else {
    map.fitBounds(L.latLngBounds(pts), { padding: [56, 56], maxZoom: 15, animate: forceFit });
  }
}

function fitAll() {
  if (!map) return;
  const valid = extractValidPins(props.pins);
  if (valid.length) {
    fitMapToValidPins(valid, true);
  } else {
    map.setView([15.2287, 104.8564], 10, { animate: true });
  }
}

function setTileStyle(key) {
  if (!map || !TILE_LAYERS[key]) return;
  activeTileKey.value = key;
  if (tileLayer) {
    map.removeLayer(tileLayer);
  }
  const cfg = TILE_LAYERS[key];
  tileLayer = L.tileLayer(cfg.url, cfg.options).addTo(map);
}

function computePixelSpreadPositions(valid) {
  const withPoint = valid.map((v) => ({
    ...v,
    pt: map.latLngToLayerPoint([v.lat, v.lng]),
  }));

  // Group pins whose marker icons would overlap in current screen pixels (< 44px apart).
  const clusters = [];
  const MERGE_DISTANCE_PX = 44;
  for (const item of withPoint) {
    let targetCluster = null;
    for (const cluster of clusters) {
      const dx = item.pt.x - cluster.anchorPt.x;
      const dy = item.pt.y - cluster.anchorPt.y;
      if (Math.hypot(dx, dy) < MERGE_DISTANCE_PX) {
        targetCluster = cluster;
        break;
      }
    }
    if (!targetCluster) {
      clusters.push({
        anchorLat: item.lat,
        anchorLng: item.lng,
        anchorPt: item.pt,
        items: [item],
      });
    } else {
      targetCluster.items.push(item);
    }
  }

  const placed = [];
  for (const cluster of clusters) {
    if (cluster.items.length === 1) {
      const only = cluster.items[0];
      placed.push({
        ...only,
        displayLat: only.lat,
        displayLng: only.lng,
        anchorLat: only.lat,
        anchorLng: only.lng,
        isSpiderfied: false,
      });
      continue;
    }

    const n = cluster.items.length;
    const radiusPx = n === 2 ? 38 : Math.max(40, Math.min(64, 28 + n * 5));
    const startAngle = n === 2 ? 0 : -Math.PI / 2;

    cluster.items.forEach((item, idx) => {
      const angle = startAngle + (2 * Math.PI * idx) / n;
      const offsetPt = L.point(
        cluster.anchorPt.x + Math.cos(angle) * radiusPx,
        cluster.anchorPt.y + Math.sin(angle) * radiusPx
      );
      const ll = map.layerPointToLatLng(offsetPt);
      placed.push({
        ...item,
        displayLat: ll.lat,
        displayLng: ll.lng,
        anchorLat: cluster.anchorLat,
        anchorLng: cluster.anchorLng,
        isSpiderfied: true,
      });
    });
  }

  return { placed, clusters };
}

function makeMarkerIcon(pin, isSelected) {
  const meta = pinTypeMeta(pin);
  const photoCount = Number(pin.photo_count || 0);
  const firstPhotoUrl = pin.photos?.[0]?.url ? esc(pin.photos[0].url) : '';
  const selectedClass = isSelected ? ' is-selected' : '';
  const hasPhotoClass = firstPhotoUrl ? ' has-photo' : '';
  const badgeHtml = photoCount > 0 ? `<span class="cm-pin-badge">📷 ${photoCount}</span>` : '';
  const innerHtml = firstPhotoUrl ? `<img src="${firstPhotoUrl}" alt="" class="cm-pin-img" />` : `<span>🏪</span>`;
  const shortName = esc(pinDisplayName(pin)).slice(0, 18);
  const showNamePill = isSelected || currentZoom.value >= 14;
  const labelPillHtml = showNamePill ? `<span class="cm-pin-name">${shortName}</span>` : '';
  return L.divIcon({
    className: `cm-pin-wrap${selectedClass}${hasPhotoClass}`,
    html: `<div class="cm-pin" style="--pin-color:${meta.color}">${innerHtml}</div>${badgeHtml}${labelPillHtml}`,
    iconSize: [40, 44],
    iconAnchor: [20, 24],
  });
}

function redraw(forceFit = false) {
  if (!map || !group) return;
  currentZoom.value = map.getZoom();
  group.clearLayers();
  markerCoordsById.clear();

  const valid = extractValidPins(props.pins);
  fitMapToValidPins(valid, forceFit);
  if (!valid.length) return;

  const { placed, clusters } = computePixelSpreadPositions(valid);

  // Draw spider legs & shared anchor points first (underneath markers).
  for (const cluster of clusters) {
    if (cluster.items.length <= 1) continue;
    L.circleMarker([cluster.anchorLat, cluster.anchorLng], {
      radius: 4.5,
      color: '#334155',
      weight: 2,
      fillColor: '#ffffff',
      fillOpacity: 1,
      interactive: false,
    }).addTo(group);
  }

  for (const item of placed) {
    if (item.isSpiderfied) {
      L.polyline(
        [
          [item.anchorLat, item.anchorLng],
          [item.displayLat, item.displayLng],
        ],
        {
          color: '#64748b',
          weight: 1.75,
          dashArray: '4 4',
          opacity: 0.78,
          interactive: false,
        }
      ).addTo(group);
    }

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
      offset: [0, -22],
      opacity: 0.96,
      className: 'cm-tooltip',
    });

    marker.on('click', () => {
      emit('select', pin);
    });

    markerCoordsById.set(String(pin.id), [displayLat, displayLng]);
  }
}

function redrawPicked() {
  if (!map) return;
  if (pickedMarker) {
    map.removeLayer(pickedMarker);
    pickedMarker = null;
  }
  if (
    props.pickedLocation?.latitude == null ||
    props.pickedLocation?.longitude == null ||
    props.pickedLocation.latitude === '' ||
    props.pickedLocation.longitude === ''
  )
    return;
  const lat = Number(props.pickedLocation?.latitude);
  const lng = Number(props.pickedLocation?.longitude);
  if (Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) {
    const pickedIcon = L.divIcon({
      className: 'cm-picked-wrap',
      html: `<div class="cm-picked-pulse"></div><div class="cm-picked-pin">📍</div>`,
      iconSize: [38, 38],
      iconAnchor: [19, 32],
    });
    pickedMarker = L.marker([lat, lng], {
      icon: pickedIcon,
      draggable: Boolean(props.selectMode),
      zIndexOffset: 900,
    })
      .addTo(map)
      .bindTooltip('จุดที่จะปักหมุด (ลากขยับได้)', { direction: 'top', offset: [0, -26], className: 'cm-tooltip' });

    pickedMarker.on('dragend', (ev) => {
      const ll = ev.target.getLatLng();
      emit('picked', {
        latitude: Number(ll.lat.toFixed(7)),
        longitude: Number(ll.lng.toFixed(7)),
      });
    });

    map.panTo([lat, lng], { animate: true });
  }
}

const onZoomEnd = () => redraw(false);

onMounted(() => {
  map = L.map(element.value, { zoomControl: true }).setView([15.2287, 104.8564], 10);
  setTileStyle(activeTileKey.value);
  group = L.layerGroup().addTo(map);
  map.on('zoomend', onZoomEnd);
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
  setTimeout(() => {
    map?.invalidateSize();
    redraw(true);
  }, 80);
  if (typeof ResizeObserver !== 'undefined' && element.value) {
    resizeObs = new ResizeObserver(() => {
      map?.invalidateSize();
      redraw(false);
    });
    resizeObs.observe(element.value);
  }
});

watch(
  () => props.pins,
  () => redraw(false),
  { deep: true }
);
watch(
  () => props.selectedPinId,
  (id) => {
    redraw(false);
    if (map && id != null) {
      const coords = markerCoordsById.get(String(id));
      if (coords) {
        map.setView(coords, Math.max(map.getZoom(), 14), { animate: true });
      }
    }
  }
);
watch(() => props.pickedLocation, redrawPicked, { deep: true });

defineExpose({ fitAll });

onBeforeUnmount(() => {
  map?.off('zoomend', onZoomEnd);
  resizeObs?.disconnect();
  map?.remove();
});
</script>

<template>
  <div class="cm-shell" :style="{ height: props.height }">
    <div ref="element" class="cm-canvas"></div>

    <!-- Floating Top-Right Toolbar: Tile switcher + Fit All -->
    <div class="cm-floating-toolbar" @click.stop>
      <div class="cm-tile-pills" role="group" aria-label="รูปแบบแผนที่">
        <button
          v-for="(cfg, key) in TILE_LAYERS"
          :key="key"
          type="button"
          :class="['cm-tool-btn', { active: activeTileKey === key }]"
          @click="setTileStyle(key)"
        >
          {{ cfg.label }}
        </button>
      </div>
      <button type="button" class="cm-tool-btn cm-fit-btn" title="ซูมดูหมุดทั้งหมด" @click="fitAll">🎯 ดูทั้งหมด</button>
    </div>

    <!-- Floating top-center banner when in interactive pinning mode -->
    <div v-if="props.selectMode && props.pickingHint" class="cm-picking-banner" @click.stop>
      <span class="cm-picking-dot"></span>
      <span>{{ props.pickingHint }}</span>
    </div>
  </div>
</template>

<style scoped>
.cm-shell {
  position: relative;
  z-index: 1;
  isolation: isolate;
  border-radius: 18px;
  overflow: hidden;
  border: 1px solid #dbe5f0;
  background: #eaf1f8;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
}

.cm-canvas {
  width: 100%;
  height: 100%;
}

:deep(.leaflet-control-zoom) {
  border: 0 !important;
  box-shadow: 0 6px 18px rgba(15, 23, 42, 0.14) !important;
  border-radius: 14px !important;
  overflow: hidden;
}

:deep(.leaflet-control-zoom a) {
  width: 34px !important;
  height: 34px !important;
  line-height: 34px !important;
  color: #0f172a !important;
  background: rgba(255, 255, 255, 0.96) !important;
  border-bottom: 1px solid #e2e8f0 !important;
  font-weight: 700 !important;
}

:deep(.leaflet-control-zoom a:hover) {
  background: #f0f9ff !important;
  color: #0284c7 !important;
}

.cm-floating-toolbar {
  position: absolute;
  top: 12px;
  right: 12px;
  left: 56px;
  z-index: 500;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  flex-wrap: wrap;
  pointer-events: none;
}

.cm-floating-toolbar > * {
  pointer-events: auto;
}

.cm-tile-pills {
  display: flex;
  align-items: center;
  max-width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
  background: rgba(255, 255, 255, 0.94);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(203, 213, 225, 0.85);
  border-radius: 999px;
  padding: 3px;
  box-shadow: 0 6px 18px rgba(15, 23, 42, 0.12);
}

.cm-tile-pills::-webkit-scrollbar {
  display: none;
}

.cm-tool-btn {
  border: 0;
  background: transparent;
  color: #334155;
  font-family: var(--font);
  font-size: 12.5px;
  font-weight: 600;
  padding: 6px 12px;
  border-radius: 999px;
  cursor: pointer;
  min-height: 30px;
  flex-shrink: 0;
  transition: all 0.15s ease;
  white-space: nowrap;
}

@media (max-width: 640px) {
  .cm-floating-toolbar {
    top: 10px;
    right: 10px;
    left: 54px;
    gap: 6px;
  }

  .cm-tool-btn {
    font-size: 11.5px;
    padding: 5px 10px;
    min-height: 28px;
  }

  .cm-fit-btn {
    padding: 5px 10px;
  }
}

.cm-tool-btn:hover {
  background: #f1f5f9;
  color: #0f172a;
}

.cm-tool-btn.active {
  background: linear-gradient(135deg, #0284c7, #0ea5e9);
  color: #ffffff;
  box-shadow: 0 3px 10px rgba(2, 132, 199, 0.28);
}

.cm-fit-btn {
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(203, 213, 225, 0.85);
  border-radius: 999px;
  padding: 6px 13px;
  box-shadow: 0 6px 18px rgba(15, 23, 42, 0.12);
}

.cm-fit-btn:hover {
  background: #ffffff;
  border-color: #0284c7;
  color: #0284c7;
}

.cm-picking-banner {
  position: absolute;
  bottom: 12px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 500;
  background: rgba(15, 23, 42, 0.88);
  backdrop-filter: blur(6px);
  color: #ffffff;
  border-radius: 99px;
  padding: 7px 14px;
  font-size: 12.5px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.25);
  max-width: calc(100% - 24px);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.cm-picking-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.28);
  flex-shrink: 0;
}

:deep(.cm-pin-wrap) {
  background: transparent;
  border: 0;
  position: relative;
}

:deep(.cm-pin) {
  width: 36px;
  height: 36px;
  border-radius: 50% 50% 50% 6px;
  transform: rotate(-45deg);
  background: var(--pin-color, #0284c7);
  border: 3px solid #ffffff;
  box-shadow: 0 6px 14px rgba(15, 26, 51, 0.28);
  display: grid;
  place-items: center;
  overflow: hidden;
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;
}

:deep(.cm-pin span) {
  transform: rotate(45deg);
  font-size: 15px;
  line-height: 1;
}

:deep(.cm-pin-img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transform: rotate(45deg) scale(1.25);
  display: block;
}

:deep(.cm-pin-wrap.is-selected .cm-pin) {
  transform: rotate(-45deg) scale(1.22);
  box-shadow:
    0 0 0 6px rgba(2, 132, 199, 0.28),
    0 8px 18px rgba(15, 26, 51, 0.34);
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
  font-size: 11.5px;
  font-weight: 700;
  line-height: 1.3;
  white-space: nowrap;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.25);
}

:deep(.cm-pin-name) {
  position: absolute;
  top: 38px;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(255, 255, 255, 0.95);
  color: #0f172a;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  padding: 1px 6px;
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.14);
  pointer-events: none;
}

:deep(.cm-picked-wrap) {
  background: transparent;
  border: 0;
  position: relative;
}

:deep(.cm-picked-pulse) {
  position: absolute;
  inset: 2px;
  border-radius: 50%;
  background: rgba(16, 185, 129, 0.28);
  animation: cmPulse 1.6s infinite ease-out;
}

:deep(.cm-picked-pin) {
  position: relative;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: #059669;
  border: 3px solid #ffffff;
  box-shadow: 0 6px 16px rgba(5, 150, 105, 0.4);
  display: grid;
  place-items: center;
  font-size: 18px;
}

@keyframes cmPulse {
  0% {
    transform: scale(0.85);
    opacity: 0.9;
  }
  100% {
    transform: scale(1.75);
    opacity: 0;
  }
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
