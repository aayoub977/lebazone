"use client";

// Custom (Lebazone): header of a seller's store page (/{zone}/products?seller=ID) - their
// banner, name and short "about"; the product list below is the normal one, filtered.
import { useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import * as api from "@/api/apiRoutes";
import useUrlProductFilters from "@/hooks/useUrlProductFilters";

type StoreCat = { id: number; name: string; products: number };
type Store = { id: number; name: string; city: string; about: string; banner: string; products: number; categories?: StoreCat[] };

export default function SellerStoreHeader() {
  const params = useSearchParams();
  const sellerId = params?.get("seller") || "";
  const activeCat = params?.get("scat") || "";
  const { setSellerCategoryId } = useUrlProductFilters() as any;
  const [store, setStore] = useState<Store | null>(null);

  useEffect(() => {
    let alive = true;
    if (!sellerId) {
      setStore(null);
      return;
    }
    api.getSellerStore(sellerId)
      .then((res: any) => { if (alive) setStore(res?.status == 1 ? res.data : null); })
      .catch(() => { if (alive) setStore(null); });
    return () => { alive = false; };
  }, [sellerId]);

  if (!store) return null;
  return (
    <section className="container mt-4" aria-label={store.name}>
      <div className="overflow-hidden rounded-xl border cardBorder">
        {store.banner ? (
          <img src={store.banner} alt={store.name} className="block w-full aspect-[3/1] md:aspect-[4/1] object-cover" />
        ) : (
          <div className="w-full aspect-[5/1] md:aspect-[8/1] bg-gradient-to-r from-red-600 to-red-400" aria-hidden="true" />
        )}
        <div className="flex flex-wrap items-center gap-3 p-4">
          <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-red-600 text-xl font-bold text-white" aria-hidden="true">
            {store.name.slice(0, 1).toUpperCase()}
          </div>
          <div className="min-w-0 flex-1">
            <h1 className="text-xl font-bold textColor">{store.name}</h1>
            <p className="text-sm text-gray-600 dark:text-zinc-300">
              {store.city ? store.city + " · " : ""}{store.products} product{store.products === 1 ? "" : "s"} · sold and delivered by Lebazone
            </p>
          </div>
        </div>
        {store.about && (
          <p className="px-4 pb-4 text-sm text-gray-700 dark:text-zinc-300 whitespace-pre-line">{store.about}</p>
        )}
        {/* Custom (Lebazone): the seller's own categories */}
        {!!store.categories?.length && (
          <div className="flex gap-2 overflow-x-auto px-4 pb-4" role="tablist" aria-label="Store categories">
            {[{ id: 0, name: "All products", products: store.products }, ...store.categories].map((c) => {
              const on = c.id === 0 ? !activeCat : String(c.id) === activeCat;
              return (
                <button
                  key={c.id}
                  type="button"
                  role="tab"
                  aria-selected={on}
                  onClick={() => setSellerCategoryId(c.id === 0 ? null : c.id)}
                  className={`shrink-0 rounded-full border px-4 py-1.5 text-sm font-medium transition ${
                    on ? "bg-red-600 border-red-600 text-white" : "cardBorder textColor hover:border-red-400"
                  }`}
                >
                  {c.name} <span className={on ? "opacity-80" : "text-gray-500"}>({c.products})</span>
                </button>
              );
            })}
          </div>
        )}
      </div>
    </section>
  );
}
