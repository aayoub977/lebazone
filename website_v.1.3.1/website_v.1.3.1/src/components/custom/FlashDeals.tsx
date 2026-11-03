"use client";

/**
 * Custom (Lebazone): "Flash deals" strip with a live countdown, shown at the top of
 * the home page while an admin-scheduled flash deal is running for the
 * shopper's zone. Products come from /customer/flash_sale (prices already
 * reflect the deal). Renders nothing when no deal is live.
 */
import React, { useEffect, useMemo, useState } from "react";
import dynamic from "next/dynamic";
import { useSelector } from "react-redux";
import { Swiper, SwiperSlide } from "swiper/react";
import { Navigation } from "swiper/modules";
import { LuZap } from "react-icons/lu";
import * as api from "@/api/apiRoutes";
import { t } from "@/utils/translation";
import useIsRtl from "@/hooks/useIsRtl";
import useIsHydrated from "@/hooks/useIsHydrated";

const VerticleProductCard = dynamic(() => import("../productcards/VerticleProductCard"), { ssr: false });

const pad = (n: number) => String(Math.max(0, n)).padStart(2, "0");

export default function FlashDeals() {
  const rtl = useIsRtl();
  const hydrated = useIsHydrated();
  const city = useSelector((s: any) => s.City?.city);
  const setting = useSelector((s: any) => s.Setting?.setting);
  const shopMode = useSelector((s: any) => s.ShopMode?.mode);
  const language = useSelector((s: any) => s.Language?.selectedLanguage);
  const latitude = city?.latitude || setting?.default_city?.latitude;
  const longitude = city?.longitude || setting?.default_city?.longitude;

  const [deal, setDeal] = useState<any>(null);
  const [offsetMs, setOffsetMs] = useState(0); // server clock − browser clock
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (!hydrated || !latitude || !longitude) return;
    let cancelled = false;
    api.getFlashSale({ latitude, longitude })
      .then((res) => {
        if (cancelled) return;
        const d = res?.status === 1 || res?.status === true ? res?.data : null;
        if (d?.server_time) setOffsetMs(new Date(d.server_time).getTime() - Date.now());
        setDeal(d && Array.isArray(d.products) && d.products.length ? d : null);
      })
      .catch(() => !cancelled && setDeal(null));
    return () => { cancelled = true; };
  }, [hydrated, latitude, longitude, shopMode, language?.id]);

  useEffect(() => {
    if (!deal) return;
    const id = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(id);
  }, [deal]);

  const remaining = useMemo(() => {
    if (!deal?.ends_at) return 0;
    return Math.max(0, new Date(deal.ends_at).getTime() - (now + offsetMs));
  }, [deal, now, offsetMs]);

  if (!hydrated || !deal || remaining <= 0) return null;

  const totalSec = Math.floor(remaining / 1000);
  const days = Math.floor(totalSec / 86400);
  const hrs = Math.floor((totalSec % 86400) / 3600);
  const mins = Math.floor((totalSec % 3600) / 60);
  const secs = totalSec % 60;
  const units = [
    ...(days > 0 ? [[days, t("countdown_days") || "Days"]] : []),
    [hrs, t("countdown_hours") || "Hours"],
    [mins, t("minutes") || "Minutes"],
    [secs, t("countdown_seconds") || "Seconds"],
  ] as [number, string][];

  return (
    <section className="container px-3 md:px-4 my-4" aria-label={deal.title}>
      <div
        className="rounded-2xl p-3 md:p-4"
        style={{ background: "linear-gradient(135deg, color-mix(in srgb, var(--primary-color) 12%, transparent), color-mix(in srgb, #F97316 10%, transparent))" }}
      >
        <div className="flex flex-wrap items-center justify-between gap-3 mb-3">
          <div className="flex items-center gap-2">
            <span className="flex h-9 w-9 items-center justify-center rounded-full text-white" style={{ background: "#F97316" }}>
              <LuZap size={20} />
            </span>
            <div className="leading-tight">
              <div className="font-extrabold text-lg md:text-xl textColor">{deal.title || t("flash_sale") || "Flash Sale"}</div>
              <div className="text-xs SecondaryTextColor">{t("ends_in") || "Ends in"}</div>
            </div>
          </div>
          <div className="flex items-center gap-1.5" dir="ltr">
            {units.map(([v, label], i) => (
              <React.Fragment key={label}>
                {i > 0 && <span className="font-bold primaryColor">:</span>}
                <span className="flex min-w-[46px] flex-col items-center rounded-lg px-2 py-1 text-white" style={{ background: "var(--primary-color)" }}>
                  <span className="font-extrabold tabular-nums text-base leading-none">{pad(v)}</span>
                  <span className="text-[9px] uppercase opacity-90">{label}</span>
                </span>
              </React.Fragment>
            ))}
          </div>
        </div>
        <Swiper
          key={String(rtl)}
          modules={[Navigation]}
          spaceBetween={12}
          breakpoints={{
            0: { slidesPerView: 1.6, spaceBetween: 10 },
            500: { slidesPerView: 2.3, spaceBetween: 10 },
            768: { slidesPerView: 3.4, spaceBetween: 12 },
            1024: { slidesPerView: 4.6, spaceBetween: 12 },
            1280: { slidesPerView: 6.2, spaceBetween: 12 },
          }}
        >
          {deal.products.map((product: any) => (
            <SwiperSlide key={product?.variant_id || product?.id} className="h-auto">
              <VerticleProductCard product={product} />
            </SwiperSlide>
          ))}
        </Swiper>
      </div>
    </section>
  );
}
