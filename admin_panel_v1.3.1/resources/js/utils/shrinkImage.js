// Custom (Lebazone): phone photos are 3-6 MB each - shown and uploaded at full size they make
// the page stutter or the phone reload it. Shrink to 1600 px JPEG first (turned upright).
export async function shrinkImage(file, max = 1600, quality = 0.85) {
    try {
        if (!file || !(file.type || '').startsWith('image/') || file.type === 'image/gif') return file;
        let bmp;
        try { bmp = await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (e) { bmp = await createImageBitmap(file); }
        const k = Math.min(1, max / Math.max(bmp.width, bmp.height));
        const w = Math.max(1, Math.round(bmp.width * k)), h = Math.max(1, Math.round(bmp.height * k));
        const c = document.createElement('canvas');
        c.width = w; c.height = h;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);
        ctx.drawImage(bmp, 0, 0, w, h);
        if (bmp.close) bmp.close();
        const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', quality));
        if (!blob) return file;
        return new File([blob], (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch (e) {
        return file; // the server resizes it anyway
    }
}
