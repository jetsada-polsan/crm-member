export const PHOTO_CATEGORY_META = {
  storefront: { label: 'หน้าร้าน', icon: '🏪', tag: 'blue' },
  interior: { label: 'ภายในร้าน', icon: '🏬', tag: 'purple' },
  products: { label: 'สินค้า', icon: '📦', tag: 'green' },
  competitor: { label: 'คู่แข่ง', icon: '📊', tag: 'orange' },
  customer_discussion: { label: 'พูดคุยกับลูกค้า', icon: '🤝', tag: 'green' },
  other: { label: 'อื่นๆ', icon: '📷', tag: 'gray' },
};

export const VISIT_RESULT_META = {
  visited: { label: 'เข้าพบเรียบร้อย', tag: 'green' },
  interested: { label: 'สนใจสินค้า', tag: 'blue' },
  follow_up: { label: 'ต้องติดตามต่อ', tag: 'orange' },
  not_interested: { label: 'ยังไม่สนใจ', tag: 'gray' },
  not_found: { label: 'ไม่พบลูกค้า', tag: 'red' },
};

export const PIN_SOURCE_LABELS = {
  visit_gps: 'GPS เข้าพบหน้างาน',
  gps: 'GPS อุปกรณ์',
  manual: 'ปักหมุดบนแผนที่',
  map_link: 'ลิงก์แผนที่',
};

export function pinDisplayName(pin) {
  return pin?.customer_name || pin?.shop_name || 'ร้านค้า';
}

export function pinCodeLabel(pin) {
  if (pin?.erp_customer_code) return pin.erp_customer_code;
  if (pin?.crm_lead_id) return `LEAD-${pin.crm_lead_id}`;
  return '';
}

export function pinTypeMeta(pin) {
  if (pin?.erp_customer_id) {
    return { kind: 'erp', label: 'ลูกค้า ERP', tag: 'blue', color: '#2f63e8', code: pin.erp_customer_code || 'ERP' };
  }
  if (pin?.converted_erp_customer_id) {
    return {
      kind: 'converted',
      label: 'Lead เชื่อม ERP แล้ว',
      tag: 'purple',
      color: '#7c3aed',
      code: `LEAD-${pin.crm_lead_id || ''}`,
    };
  }
  if (pin?.approval_status === 'approved') {
    return {
      kind: 'approved',
      label: 'Lead อนุมัติแล้ว',
      tag: 'green',
      color: '#0cab7e',
      code: `LEAD-${pin.crm_lead_id || ''}`,
    };
  }
  return {
    kind: 'pending',
    label: 'Lead รออนุมัติ',
    tag: 'orange',
    color: '#ed9b2d',
    code: `LEAD-${pin.crm_lead_id || ''}`,
  };
}

export function photoCategoryMeta(category) {
  return PHOTO_CATEGORY_META[category] || PHOTO_CATEGORY_META.other;
}

export function visitResultMeta(result) {
  return VISIT_RESULT_META[result] || { label: result || 'เข้าพบ', tag: 'blue' };
}

export function pinSourceLabel(source) {
  return PIN_SOURCE_LABELS[source] || source || 'GPS';
}

export function formatThaiDateTime(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' });
}

export function googleMapsUrl(pin) {
  const lat = Number(pin?.latitude);
  const lng = Number(pin?.longitude);
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return 'https://www.google.com/maps';
  return `https://www.google.com/maps?q=${lat},${lng}`;
}

export function pinLightboxItems(pin) {
  const name = pinDisplayName(pin);
  return (pin?.photos || []).map((photo) => {
    const cat = photoCategoryMeta(photo.category);
    return {
      src:
        photo.url ||
        (photo.kind === 'lead' ? `/api/crm/lead-photos/${photo.id}?inline=1` : `/api/crm/photos/${photo.id}?inline=1`),
      title: `${name} · ${cat.label}`,
      category: `${cat.icon} ${cat.label}`,
      capturedAt: formatThaiDateTime(photo.captured_at),
      consentConfirmed: Boolean(photo.consent_confirmed),
      compressedOnClient: Boolean(photo.compressed_on_client),
      latitude: photo.latitude,
      longitude: photo.longitude,
    };
  });
}
