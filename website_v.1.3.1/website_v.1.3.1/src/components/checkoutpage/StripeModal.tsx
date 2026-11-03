import React, { useEffect, useMemo, useState } from "react";
import {
  Dialog as DialogRaw,
  DialogContent as DialogContentRaw,
  DialogHeader as DialogHeaderRaw,
} from "@/components/ui/dialog";
import { RiCloseFill } from "react-icons/ri";
import {
  useStripe,
  useElements,
  CardElement,
  PaymentRequestButtonElement,
  ElementsConsumer,
  Elements,
} from "@stripe/react-stripe-js";
import { loadStripe } from "@stripe/stripe-js";
import { useDispatch, useSelector } from "react-redux";
import { t } from "@/utils/translation";
import { addUserBalance } from "@/redux/slices/userSlice";
import { useRouter } from "next/navigation";
import useCurrency from "@/hooks/useCurrency";

// Custom (Lebazone): Stripe account country per charge currency (Apple Pay / Google Pay
// need the merchant account's country). UAE accounts charge AED.
const WALLET_COUNTRY: Record<string, string> = { AED: "AE", SAR: "SA", USD: "US", EUR: "IE", GBP: "GB" };

// src/components/ui/dialog.tsx (out of scope for this migration batch, owned by a
// concurrent agent) is a .tsx file but its exports are still untyped forwardRef
// components, so TS infers overly-narrow prop types for them (e.g. rejects the
// `className`/`title` props these call sites already relied on pre-TS). Cast to
// `any` at the boundary rather than touch the shared ui component — same pattern
// as `StripeModalAny` in useOrderPlacement.tsx.
const Dialog = DialogRaw as any;
const DialogContent = DialogContentRaw as any;
const DialogHeader = DialogHeaderRaw as any;

// Returned shape is passed straight into Stripe.js's <CardElement>/<Elements>
// `options` props; it doesn't line up 1:1 with @stripe/stripe-js's strict
// StripeCardElementOptions/StripeElementsOptions types (e.g. iconStyle isn't on
// StripeElementsOptions), so this stays `any` rather than fighting third-party
// SDK typings for an internal options builder.
const getCardOptions = (theme: string): any => {
  return {
    iconStyle: "solid",
    style: {
      base: {
        color: theme === "dark" ? "#87bbfd" : "#87bbfd",

        fontWeight: 500,
        fontFamily: "Roboto, Open Sans, Segoe UI, sans-serif",
        fontSize: "16px",
        fontSmoothing: "antialiased",
        ":-webkit-autofill": {
          color: theme === "dark" ? "#fce883" : "#fce883",
        },
        "::placeholder": {
          color: theme === "dark" ? "#87bbfd" : "#87bbfd",
        },
      },
      invalid: {
        color: "#fa755a",
        iconColor: "#fa755a",
      },
    },
  };
};
interface CheckoutFormProps {
  clientSecret: string;
  setShowStripe: (show: boolean) => void;
  amount: number;
  transactionId?: string;
  /** Only passed by WalletBalanceModal's wallet top-up flow; the order-placement
   * flow (CheckoutUI / useOrderPlacement) never passes this. */
  setWalletModal?: (show: boolean) => void;
  stripeOrderId?: string;
  type: string;
  /** Passed through by InjectedCheckoutForm but unused here — CheckoutForm reads
   * stripe/elements via the useStripe()/useElements() hooks instead. Kept as
   * optional passthrough props to preserve the existing call signature. */
  elements?: unknown;
  stripe?: unknown;
  /** "applepay" | "googlepay" = wallet-only sheet; empty = card form. */
  wallet?: string;
}

const CheckoutForm = ({
  clientSecret,
  setShowStripe,
  amount,
  transactionId,
  setWalletModal,
  stripeOrderId,
  type,
  wallet,
}: CheckoutFormProps) => {
  const dispatch = useDispatch();
  const user = useSelector((state: any) => state?.User?.user);
  const theme = useSelector((state: any) => state.Theme.theme);
  const router = useRouter();
  const cardElementOptions = getCardOptions(theme);
  const stripe = useStripe();
  const elements = useElements();
  const [isLoading, setIsLoading] = useState(false);
  // Custom (Lebazone): Apple Pay / Google Pay via Stripe's Payment Request button.
  const { currencyCode } = useCurrency();
  const [paymentRequest, setPaymentRequest] = useState<any>(null);
  const [walletUnavailable, setWalletUnavailable] = useState(false);
  useEffect(() => {
    if (!wallet || !stripe || !clientSecret || !amount) return;
    const cur = String(currencyCode || "").toUpperCase();
    const country = WALLET_COUNTRY[cur];
    if (!country) return;
    const pr = stripe.paymentRequest({
      country,
      currency: cur.toLowerCase(),
      total: { label: "Lebazone", amount: Math.round(Number(amount) * 100) },
      requestPayerName: true,
      // Only the wallet the shopper picked (Apple Pay OR Google Pay).
      disableWallets: wallet === "applepay" ? ["googlePay", "link", "browserCard"] : ["applePay", "link", "browserCard"],
    } as any);
    let active = true;
    pr.canMakePayment().then((r: any) => {
      if (!active) return;
      const ok = r && (wallet === "applepay" ? r.applePay : r.googlePay);
      if (ok) setPaymentRequest(pr);
      else setWalletUnavailable(true);
    });
    pr.on("paymentmethod", async (ev: any) => {
      const { paymentIntent, error } = await stripe.confirmCardPayment(
        clientSecret, { payment_method: ev.paymentMethod.id }, { handleActions: false },
      );
      if (error) {
        ev.complete("fail");
        return;
      }
      ev.complete("success");
      let status: string | undefined = paymentIntent?.status;
      if (status === "requires_action") {
        const again = await stripe.confirmCardPayment(clientSecret);
        status = again.paymentIntent?.status;
      }
      if (status === "succeeded") await handleAddPayment();
    });
    return () => { active = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stripe, clientSecret, amount, currencyCode, wallet]);
  // const type = stripeOrderId ? "order" : "wallet";
  const handleOnSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setIsLoading(true);
    if (!stripe || !elements) {
      console.log("Stripe not loaded yet");
      setIsLoading(false); // re-enable the pay button; Stripe wasn't ready
      return;
    }

    try {
      const { paymentIntent, error } = await stripe.confirmCardPayment(
        clientSecret,
        {
          payment_method: {
            // elements is already confirmed truthy above; getElement's return type is
            // nullable per @stripe/stripe-js's types even though the element is known
            // mounted here (CardElement is rendered unconditionally below).
            card: elements.getElement(CardElement)!,
            // Custom (Lebazone): upstream sent a hard-coded San Francisco billing
            // address with every card payment; send only the customer's name.
            billing_details: {
              name: user?.name,
            },
          },
        },
      );
      if (paymentIntent?.status === "succeeded") {
        await handleAddPayment();
      } else {
        setShowStripe(false);
        // setWalletModal is only passed by the wallet top-up flow (WalletBalanceModal);
        // the order-placement flow (CheckoutUI / useOrderPlacement) never passes it, so
        // this was already an implicit no-op there pre-TS. Optional call preserves that.
        setWalletModal?.(false);
        const urlOptions: { type: string; status: string; order_id?: string } = {
          type: type,
          status: "failed",
        };
        if (stripeOrderId) {
          urlOptions.order_id = stripeOrderId;
        }
        const qs = new URLSearchParams(
          Object.fromEntries(
            Object.entries(urlOptions).map(([k, v]) => [k, String(v)]),
          ),
        ).toString();
        router.push(`/web-payment-status?${qs}`);
      }
    } catch (error) {
      console.log("Error", error);
    }
    setIsLoading(false);
  };

  // No add_transaction call here: Stripe payments are recorded server-side off
  // the payment intent (webhook), so posting one from the client would double
  // up the record. Other gateways (Razorpay/Paystack) still report from the
  // client because they have no such server-side confirmation.
  const handleAddPayment = async () => {
    if (type == "wallet") {
      dispatch(addUserBalance({ data: amount }));
      setWalletModal?.(false);
    }
    setShowStripe(false);
    const urlOptions: {
      type: string;
      status_code: number;
      status: string;
      order_id?: string;
    } = {
      type: type,
      status_code: 200,
      status: "success",
    };
    if (stripeOrderId) {
      urlOptions.order_id = stripeOrderId;
    }
    const qs = new URLSearchParams(
      Object.fromEntries(
        Object.entries(urlOptions).map(([k, v]) => [k, String(v)]),
      ),
    ).toString();
    router.push(`/web-payment-status?${qs}`);
  };

  return (
    <form
      onSubmit={handleOnSubmit}
      className={"min-h-[150px]  flex flex-col justify-center gap-4 mt-3"}
    >
      <div className="h-full flex flex-col justify-evenly gap-4">
        {wallet ? (
          paymentRequest ? (
            <PaymentRequestButtonElement options={{ paymentRequest }} />
          ) : walletUnavailable ? (
            <p className="text-center text-sm">
              {wallet === "applepay"
                ? "Apple Pay isn't available on this device or browser."
                : "Google Pay isn't available in this browser."}{" "}
              Close this and choose {t("stripe")} instead.
            </p>
          ) : (
            <p className="text-center text-sm">{t("loading")}</p>
          )
        ) : (
          <CardElement options={cardElementOptions} />
        )}
      </div>
      <div className={wallet ? "hidden" : "flex justify-center"}>
        <button
          className={
            "text-white text-base font-bold px-4 py-2 mt-4 primaryBackColor rounded-sm"
          }
          disabled={!stripe || isLoading}
        >
          {isLoading ? t("loading") : t("submit")}
        </button>
      </div>
    </form>
  );
};

interface InjectedCheckoutFormProps {
  clientSecret: string;
  transactionId?: string;
  setShowStripe: (show: boolean) => void;
  amount: number;
  setWalletModal?: (show: boolean) => void;
  stripeOrderId?: string;
  type: string;
  /** Passed through by StripeModal but unused here (Elements/CheckoutForm read
   * stripe via context/hooks instead). Kept as optional passthrough props to
   * preserve the existing call signature. */
  stripe?: unknown;
  options?: unknown;
  wallet?: string;
}

const InjectedCheckoutForm = ({
  clientSecret,
  transactionId,
  setShowStripe,
  amount,
  setWalletModal,
  stripeOrderId,
  type,
  wallet,
}: InjectedCheckoutFormProps) => {
  return (
    <ElementsConsumer>
      {({ elements, stripe }) => (
        <CheckoutForm
          elements={elements}
          stripe={stripe}
          clientSecret={clientSecret}
          transactionId={transactionId}
          setShowStripe={setShowStripe}
          amount={amount}
          setWalletModal={setWalletModal}
          stripeOrderId={stripeOrderId}
          type={type}
          wallet={wallet}
        />
      )}
    </ElementsConsumer>
  );
};

interface StripeModalProps {
  showStripe: boolean;
  setShowStripe: (show: boolean) => void;
  clientSecret: string;
  stripeTransId?: string;
  amount: number;
  /** Only passed by WalletBalanceModal's wallet top-up flow. */
  setWalletModal?: (show: boolean) => void;
  stripeOrderId?: string;
  type: string;
  /** "applepay" | "googlepay" | undefined (card). */
  wallet?: string;
}

const StripeModal = ({
  showStripe,
  setShowStripe,
  clientSecret,
  stripeTransId,
  amount,
  setWalletModal,
  stripeOrderId,
  type,
  wallet,
}: StripeModalProps) => {
  const title = wallet === "applepay" ? "Apple Pay" : wallet === "googlepay" ? "Google Pay" : t("stripe");
  const setting = useSelector((state: any) => state.Setting);
  const stripePromise = useMemo(() => {
    if (!setting?.payment_setting?.stripe_publishable_key) return null;
    return loadStripe(setting.payment_setting.stripe_publishable_key);
  }, [setting]);
  const theme = useSelector((state: any) => state.Theme.theme);
  const cardElementOptions = getCardOptions(theme);

  return (
    <Dialog open={showStripe} className="bg-gray-400">
      <DialogContent className="max-w-[600px]" title={title}>
        <DialogHeader>
          <div className="flex flex-row justify-between items-center">
            <p className="text-2xl font-bold">{title}</p>
            <div className="closeButtonBg rounded-full p-[8px] gap-[4px]">
              <RiCloseFill size={22} onClick={() => setShowStripe(false)} />
            </div>
          </div>
        </DialogHeader>
        <div>
          {/* @stripe/react-stripe-js's <Elements> doesn't type a `clientSecret` or
              `transactionId` prop (client secret belongs inside `options`), but this
              call already passed them pre-TS as harmless extra props — cast to
              preserve that exactly rather than restructure real payment wiring. */}
          <Elements
            {...({
              stripe: stripePromise,
              options: cardElementOptions,
              clientSecret,
              transactionId: stripeTransId,
            } as any)}
          >
            <InjectedCheckoutForm
              setShowStripe={setShowStripe}
              stripe={stripePromise}
              options={cardElementOptions}
              clientSecret={clientSecret}
              transactionId={stripeTransId}
              amount={amount}
              setWalletModal={setWalletModal}
              stripeOrderId={stripeOrderId}
              type={type}
              wallet={wallet}
            />
          </Elements>
        </div>
      </DialogContent>
    </Dialog>
  );
};

export default StripeModal;
