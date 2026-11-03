// Custom (Lebazone): "Sell on Lebazone" — consignment seller application page.
import type { Metadata } from "next";
import SellWithUsPage from "@/components/custom/SellWithUsPage";

export const metadata: Metadata = {
  title: "Sell on Lebazone",
  description: "Supply your products to Lebazone — we store, sell and deliver them across Lebanon, and pay you after each sale.",
};

export default function SellWithUsRoute() {
  return <SellWithUsPage />;
}
