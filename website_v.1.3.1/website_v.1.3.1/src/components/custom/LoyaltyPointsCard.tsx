"use client";

/**
 * Custom (Lebazone): loyalty points card on the wallet page — balance, what it's
 * worth, and a one-tap "convert to wallet" button. Hidden when the programme
 * is switched off in admin → Marketing → Loyalty points.
 */
import React, { useCallback, useEffect, useState } from "react";
import { useDispatch } from "react-redux";
import { toast } from "react-toastify";
import { LuAward } from "react-icons/lu";
import * as api from "@/api/apiRoutes";
import { t } from "@/utils/translation";
import { formatCurrency } from "@/utils/helperFunction";
import useCurrency from "@/hooks/useCurrency";
import { addUserBalance } from "@/redux/slices/userSlice";

interface Props {
  /** Called after a successful conversion so the wallet history can refresh. */
  onRedeemed?: () => void;
}

export default function LoyaltyPointsCard({ onRedeemed }: Props) {
  const dispatch = useDispatch();
  const { currency, decimals } = useCurrency();
  const [data, setData] = useState<any>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api.getLoyalty().then((res) => {
      if (res?.status === 1 || res?.status === true) setData(res.data);
    }).catch(() => setData(null));
  }, []);

  useEffect(() => { load(); }, [load]);

  if (!data?.enabled) return null;

  const points = Number(data.points || 0);
  const canRedeem = points >= Number(data.min_redeem || 0) && points > 0;

  const redeem = async () => {
    setBusy(true);
    try {
      const res = await api.redeemLoyalty({ points });
      if (res?.status === 1 || res?.status === true) {
        dispatch(addUserBalance({ data: res?.data?.wallet_amount || 0 }));
        toast.success(t("loyalty_redeemed") || res?.message);
        load();
        onRedeemed?.();
      } else {
        toast.error(res?.message || t("something_went_wrong"));
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="rounded-xl border cardBorder bg-white dark:bg-zinc-900 p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div className="flex items-center gap-4">
        <span className="shrink-0 w-12 h-12 rounded-2xl flex items-center justify-center text-white" style={{ background: "#F97316" }}>
          <LuAward size={24} />
        </span>
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider SecondaryTextColor">{t("loyalty_points") || "Loyalty points"}</p>
          <p className="text-2xl font-extrabold tabular-nums fontColor">
            {points.toLocaleString()}{" "}
            <span className="text-sm font-semibold SecondaryTextColor">
              ≈ {formatCurrency(data.wallet_value || 0, currency, decimals)}
            </span>
          </p>
          <p className="text-xs SecondaryTextColor mt-0.5">
            {(t("loyalty_earn_hint") || "Earn {points} points for every {amount} you spend.")
              .replace("{points}", String(data.points_per_unit))
              .replace("{amount}", formatCurrency(1, currency, 0))}
            {!canRedeem && data.min_redeem > 0 && (
              <> {(t("loyalty_min_hint") || "Redeem from {min} points.").replace("{min}", String(data.min_redeem))}</>
            )}
          </p>
        </div>
      </div>
      <button
        type="button"
        disabled={!canRedeem || busy}
        onClick={redeem}
        className="shrink-0 rounded-xl px-5 py-3 font-bold text-white primaryBackColor disabled:opacity-50"
      >
        {busy ? "…" : t("loyalty_convert") || "Convert to wallet"}
      </button>
    </div>
  );
}
