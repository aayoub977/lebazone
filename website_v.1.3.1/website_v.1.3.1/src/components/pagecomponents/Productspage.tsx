"use client";
import React, { Suspense, useEffect } from "react";
import ProductsList from "@/components/productslist/ProductsList";
import SellerStoreHeader from "@/components/productslist/SellerStoreHeader";
import { useSelector } from "react-redux";
const Productpage = ({ initialProducts = null }) => {
  const language = useSelector((state: any) => state.Language.selectedLanguage);

  useEffect(() => {}, [language?.id]);
  return (
    <>
      <Suspense fallback={null}>
        <SellerStoreHeader />
      </Suspense>
      <ProductsList initialProducts={initialProducts} />
    </>
  );
};

export default Productpage;
