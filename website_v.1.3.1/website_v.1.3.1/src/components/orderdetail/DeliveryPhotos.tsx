"use client";
// Custom (Lebazone): photos the driver took of the boxes (when collecting) and at the door (when delivering).
import React, { useEffect, useState } from "react";
import api from "@/api/axiosMiddleware";

type Photo = { type: string; url: string; at: string };

export default function DeliveryPhotos({ orderId }: { orderId: number | string }) {
  const [photos, setPhotos] = useState<Photo[]>([]);
  const [open, setOpen] = useState<string | null>(null);

  useEffect(() => {
    if (!orderId) return;
    let alive = true;
    api
      .get("order_photos", { params: { order_id: orderId } })
      .then((r) => {
        if (alive && r?.data?.status === 1) setPhotos(Array.isArray(r.data.data) ? r.data.data : []);
      })
      .catch(() => {});
    return () => {
      alive = false;
    };
  }, [orderId]);

  if (!photos.length) return null;
  return (
    <div className="rounded-lg border p-4">
      <div className="mb-3 font-semibold">📸 Delivery photos</div>
      <div className="flex flex-wrap gap-3">
        {photos.map((p) => (
          <button key={p.url} type="button" onClick={() => setOpen(p.url)} className="text-center text-xs">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={p.url} alt="" className="h-28 w-28 rounded-lg border object-cover" />
            <span className="mt-1 block opacity-70">
              {p.type === "delivered" ? "Delivered to you" : "Your boxes"}
              <br />
              {String(p.at || "").slice(0, 16)}
            </span>
          </button>
        ))}
      </div>
      {open && (
        <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/80 p-4" onClick={() => setOpen(null)}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={open} alt="" className="max-h-full max-w-full rounded-lg" />
        </div>
      )}
    </div>
  );
}
