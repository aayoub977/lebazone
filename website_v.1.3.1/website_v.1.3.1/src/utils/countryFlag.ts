// Custom (Lebazone): "Made in" is typed as free text in the admin panel ("China",
// "Turkey", "USA", "الصين"...). Turn that text into a 2-letter country code so a
// small flag can be shown next to it. Unknown text -> null (no flag, text only).

const ALIASES: Record<string, string> = {
  usa: "US", "u.s.a": "US", "u.s.a.": "US", us: "US", america: "US", "united states of america": "US",
  uk: "GB", "u.k.": "GB", england: "GB", britain: "GB", "great britain": "GB",
  uae: "AE", emirates: "AE", dubai: "AE",
  korea: "KR", "south korea": "KR", turkey: "TR", "türkiye": "TR", turkiye: "TR",
  russia: "RU", vietnam: "VN", "viet nam": "VN", taiwan: "TW", "hong kong": "HK",
  ksa: "SA", "saudi": "SA", prc: "CN", "p.r.c": "CN", "p.r.china": "CN", "made in china": "CN",
  holland: "NL", czechia: "CZ", "czech republic": "CZ",
  // Arabic
  "الصين": "CN", "لبنان": "LB", "تركيا": "TR", "الإمارات": "AE", "الامارات": "AE",
  "السعودية": "SA", "مصر": "EG", "الهند": "IN", "اليابان": "JP", "كوريا": "KR",
  "ألمانيا": "DE", "المانيا": "DE", "إيطاليا": "IT", "ايطاليا": "IT", "فرنسا": "FR",
  "أمريكا": "US", "امريكا": "US", "الولايات المتحدة": "US", "بريطانيا": "GB",
  "إسبانيا": "ES", "اسبانيا": "ES", "سوريا": "SY", "الأردن": "JO", "الاردن": "JO",
  "تايوان": "TW", "فيتنام": "VN", "تايلاند": "TH",
};

let byName: Record<string, string> | null = null;

function buildNames(): Record<string, string> {
  const map: Record<string, string> = {};
  try {
    const dn = new Intl.DisplayNames(["en"], { type: "region" });
    const A = 65;
    for (let i = 0; i < 26; i++) {
      for (let j = 0; j < 26; j++) {
        const code = String.fromCharCode(A + i) + String.fromCharCode(A + j);
        const name = dn.of(code);
        if (name && name !== code) map[name.toLowerCase()] = code;
      }
    }
  } catch {
    /* very old browser: aliases only */
  }
  return map;
}

export function countryCode(text?: string | null): string | null {
  const t = String(text || "").trim().toLowerCase().replace(/\s+/g, " ");
  if (!t) return null;
  if (ALIASES[t]) return ALIASES[t];
  if (/^[a-z]{2}$/.test(t)) return t.toUpperCase();
  if (!byName) byName = buildNames();
  return byName[t] || null;
}

/** Small flag picture URL (flagcdn.com, 20px wide), or null when the country is unknown. */
export function flagUrl(text?: string | null, width = 20): string | null {
  const c = countryCode(text);
  return c ? `https://flagcdn.com/w${width}/${c.toLowerCase()}.png` : null;
}

/** Product "made_in" is free text (string) — older payloads sent { name }. */
export function madeInName(v: any): string {
  if (!v) return "";
  if (typeof v === "string") return v.trim();
  return String(v?.name || "").trim();
}
