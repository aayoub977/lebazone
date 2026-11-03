"use client";

/**
 * Custom (Lebazone): consignment seller application. Posts to
 * /customer/seller_application; applications appear in admin → Catalog → Sellers.
 */
import React, { useState } from "react";
import { toast } from "react-toastify";
import { LuPackageCheck, LuTruck, LuWallet } from "react-icons/lu";
import * as api from "@/api/apiRoutes";
import { t } from "@/utils/translation";

const empty = { business_name: "", contact_name: "", phone: "", email: "", city: "", category: "", message: "" };

export default function SellWithUsPage() {
  const [form, setForm] = useState({ ...empty });
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const set = (k: keyof typeof empty) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.business_name || !form.contact_name || !form.phone) {
      toast.error(t("sell_required_fields") || "Please fill in your business name, name and phone.");
      return;
    }
    setBusy(true);
    try {
      const res = await api.submitSellerApplication(form);
      if (res?.status === 1 || res?.status === true) {
        setSent(true);
        setForm({ ...empty });
      } else toast.error(res?.message || t("something_went_wrong"));
    } catch {
      toast.error(t("something_went_wrong") || "Something went wrong");
    } finally {
      setBusy(false);
    }
  };

  const steps = [
    { icon: LuPackageCheck, title: t("sell_step1_title") || "You supply", text: t("sell_step1_text") || "Send us your products. We list them with great photos and descriptions." },
    { icon: LuTruck, title: t("sell_step2_title") || "We sell & deliver", text: t("sell_step2_text") || "Lebazone stores, sells and delivers your products to customers across Lebanon." },
    { icon: LuWallet, title: t("sell_step3_title") || "You get paid", text: t("sell_step3_text") || "After each sale (minus our commission) you're paid — with a live statement link." },
  ];
  const input = "w-full rounded-lg border cardBorder bg-white dark:bg-zinc-900 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-[var(--primary-color)]";

  return (
    <div className="container px-4 py-8 md:py-12 max-w-5xl">
      <div className="text-center mb-8">
        <h1 className="text-2xl md:text-4xl font-extrabold fontColor">{t("sell_title") || "Sell on Lebazone"}</h1>
        <p className="mt-2 SecondaryTextColor max-w-2xl mx-auto">
          {t("sell_subtitle") || "Reach thousands of shoppers without running a store. You bring the products — we handle the rest."}
        </p>
      </div>
      <div className="grid md:grid-cols-3 gap-4 mb-10">
        {steps.map(({ icon: Icon, title, text }) => (
          <div key={title} className="rounded-xl border cardBorder p-5 bg-white dark:bg-zinc-900">
            <span className="flex h-11 w-11 items-center justify-center rounded-xl text-white primaryBackColor"><Icon size={22} /></span>
            <h3 className="mt-3 font-bold fontColor">{title}</h3>
            <p className="text-sm SecondaryTextColor mt-1">{text}</p>
          </div>
        ))}
      </div>
      {sent ? (
        <div className="rounded-xl border cardBorder p-8 text-center bg-white dark:bg-zinc-900">
          <h2 className="text-xl font-bold fontColor">{t("sell_thanks_title") || "Thanks — we got your application!"}</h2>
          <p className="SecondaryTextColor mt-2">{t("sell_thanks_text") || "Our team will contact you within 2 business days."}</p>
        </div>
      ) : (
        <form onSubmit={submit} className="rounded-xl border cardBorder p-5 md:p-7 bg-white dark:bg-zinc-900 grid md:grid-cols-2 gap-4">
          <h2 className="md:col-span-2 text-lg font-bold fontColor">{t("sell_form_title") || "Apply to become a seller"}</h2>
          <input className={input} placeholder={(t("sell_business_name") || "Business name") + " *"} value={form.business_name} onChange={set("business_name")} />
          <input className={input} placeholder={(t("sell_contact_name") || "Your name") + " *"} value={form.contact_name} onChange={set("contact_name")} />
          <input className={input} placeholder={(t("phone") || "Phone") + " *"} value={form.phone} onChange={set("phone")} dir="ltr" />
          <input className={input} type="email" placeholder={t("email") || "Email"} value={form.email} onChange={set("email")} dir="ltr" />
          <input className={input} placeholder={t("city") || "City"} value={form.city} onChange={set("city")} />
          <input className={input} placeholder={t("sell_category") || "What do you sell?"} value={form.category} onChange={set("category")} />
          <textarea className={`${input} md:col-span-2 min-h-[110px]`} placeholder={t("sell_message") || "Tell us about your products (brands, quantities, prices)"} value={form.message} onChange={set("message")} />
          <button type="submit" disabled={busy} className="md:col-span-2 rounded-xl py-3 font-bold text-white primaryBackColor disabled:opacity-60">
            {busy ? "…" : t("sell_submit") || "Send application"}
          </button>
        </form>
      )}
    </div>
  );
}
