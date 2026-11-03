"use client";

/**
 * Custom: shows an amount in the country's optional second display currency,
 * e.g. "≈ 900,000 L.L." under a "$10.00" price. Configured per country in the
 * admin panel (Countries → Currency → Second display currency). Renders nothing
 * when no exchange rate is set, so it is safe to drop in anywhere.
 */
import useCurrency from "@/hooks/useCurrency";

interface Props {
  amount: number | string | null | undefined;
  className?: string;
  /** Hide the leading "≈" (e.g. where the space is tight). */
  noApprox?: boolean;
}

export const formatSecondary = (
  amount: number | string | null | undefined,
  secondary: { symbol: string; rate: number; decimals: number },
): string | null => {
  const n = Number(amount);
  if (!Number.isFinite(n) || !secondary?.rate) return null;
  const value = n * secondary.rate;
  const text = value.toLocaleString("en-US", {
    minimumFractionDigits: secondary.decimals,
    maximumFractionDigits: secondary.decimals,
  });
  return `${text} ${secondary.symbol}`.trim();
};

export default function SecondaryPrice({ amount, className = "", noApprox }: Props) {
  const { secondary } = useCurrency();
  if (!secondary) return null;
  const text = formatSecondary(amount, secondary);
  if (!text) return null;
  return (
    <span className={`SecondaryTextColor whitespace-nowrap ${className}`} dir="ltr">
      {noApprox ? "" : "≈ "}
      {text}
    </span>
  );
}
