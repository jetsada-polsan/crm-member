/**
 * Client-side Canvas image compression for mobile field photos before upload.
 * Keeps uploads fast over 3G/4G while preserving shopfront readability.
 */
export async function compressImageFile(file, { maxDimension = 1440, quality = 0.82 } = {}) {
  if (!file) throw new Error('กรุณาเลือกไฟล์รูปภาพ');
  const originalSize = file.size;

  const loadImage = () =>
    new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        URL.revokeObjectURL(url);
        resolve(img);
      };
      img.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error('ไม่สามารถอ่านไฟล์รูปภาพได้'));
      };
      img.src = url;
    });

  const img = await loadImage();
  const srcW = img.naturalWidth || img.width;
  const srcH = img.naturalHeight || img.height;

  const scale = Math.min(1, maxDimension / Math.max(srcW, srcH, 1));
  const width = Math.max(1, Math.round(srcW * scale));
  const height = Math.max(1, Math.round(srcH * scale));

  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(img, 0, 0, width, height);

  const mimeType = file.type === 'image/png' && originalSize < 450_000 ? 'image/png' : 'image/jpeg';
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, mimeType, quality));
  if (!blob) throw new Error('บีบอัดภาพไม่สำเร็จ');

  const ext = mimeType === 'image/png' ? 'png' : 'jpg';
  const safeBase = (file.name || 'photo').replace(/\.[^.]+$/, '');
  const finalBlob = blob.size <= originalSize || scale < 1 ? blob : file;
  const compressedFile = new File([finalBlob], `${safeBase}.${ext}`, { type: finalBlob.type || mimeType });
  const previewUrl = URL.createObjectURL(compressedFile);

  const savingsPct = originalSize > 0
    ? Math.max(0, Math.round(((originalSize - compressedFile.size) / originalSize) * 100))
    : 0;

  return {
    file: compressedFile,
    previewUrl,
    width,
    height,
    originalSize,
    compressedSize: compressedFile.size,
    savingsPct,
    compressedOnClient: finalBlob === blob,
    mimeType: compressedFile.type,
    capturedAt: new Date().toISOString(),
  };
}

export function formatBytes(bytes) {
  if (!bytes || bytes <= 0) return '0 B';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}
