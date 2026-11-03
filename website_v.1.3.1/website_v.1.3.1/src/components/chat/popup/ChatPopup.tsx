"use client";

// Custom (Lebazone): bottom-right popup whenever support or the delivery rider
// replies, on every page of the store. Tapping it opens a small chat window
// where the customer can read and answer without leaving the page.
//
// Checks GET /chat/poll every 3 s while the tab is visible (15 s in the
// background). Each check only asks for messages newer than the last one seen,
// so it stays light. Other chat views listen for the "lbz:chat-incoming" event
// to refresh themselves straight away.

import { useCallback, useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { LuX, LuMessageCircle } from "react-icons/lu";
import { toast } from "react-toastify";
import { getChatPoll, getChatMessages, sendChatMessage } from "@/api/apiRoutes";
import { mapMessages, type UiMessage } from "@/lib/chatMappers";
import { MessageList, ChatInput, type ChatInputSendArgs } from "@/components/chat/shared/ChatPrimitives";
import { t } from "@/utils/translation";

const FAST = 3000;
const SLOW = 15000;
const SHOW_MS = 8000;

interface Toast {
  key: string;
  conversationId: number;
  sender: string;
  title: string;
  avatar: string | null;
  sub: string;
  text: string;
  time: string;
  more: number;
}

interface Win {
  conversationId: number;
  title: string;
  sub: string;
  avatar: string | null;
}

const clock = (s?: string) => {
  const d = new Date(String(s || "").replace(" ", "T"));
  return isNaN(d.getTime()) ? "" : d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
};
const initials = (n?: string) =>
  String(n || "?").trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();
const attachmentLabel = (type?: string) =>
  ({ image: "📷 Photo", audio: "🎤 Voice message", video: "🎥 Video" } as Record<string, string>)[type || ""] || "📎 File";

function beep(ctxRef: React.MutableRefObject<AudioContext | null>) {
  try {
    const Ctx = (window as any).AudioContext || (window as any).webkitAudioContext;
    if (!Ctx) return;
    ctxRef.current = ctxRef.current || new Ctx();
    const ctx = ctxRef.current!;
    if (ctx.state === "suspended") ctx.resume();
    [[880, 0], [1320, 0.12]].forEach(([f, at]) => {
      const o = ctx.createOscillator();
      const g = ctx.createGain();
      o.type = "sine";
      o.frequency.value = f;
      g.gain.setValueAtTime(0.0001, ctx.currentTime + at);
      g.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + at + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.22);
      o.connect(g);
      g.connect(ctx.destination);
      o.start(ctx.currentTime + at);
      o.stop(ctx.currentTime + at + 0.25);
    });
  } catch {
    /* sound is optional */
  }
}

export default function ChatPopup() {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const [win, setWin] = useState<Win | null>(null);
  const [messages, setMessages] = useState<UiMessage[]>([]);
  const [loading, setLoading] = useState(false);
  const [sending, setSending] = useState(false);

  const lastId = useRef(0);
  const busy = useRef(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const toastTimers = useRef<Record<string, ReturnType<typeof setTimeout>>>({});
  const winRef = useRef<Win | null>(null);
  const audioCtx = useRef<AudioContext | null>(null);
  const baseTitle = useRef("");
  const unseen = useRef(0);
  const stopped = useRef(false);

  useEffect(() => {
    winRef.current = win;
  }, [win]);

  // Phones: fill exactly the visible screen (above Safari's bars and the keyboard).
  const [fitStyle, setFitStyle] = useState<React.CSSProperties | undefined>(undefined);
  const isOpen = !!win;
  useEffect(() => {
    if (!isOpen) {
      setFitStyle(undefined);
      return;
    }
    const vv = window.visualViewport;
    const fit = () => {
      if (window.innerWidth > 640) return setFitStyle(undefined);
      setFitStyle({ top: (vv ? vv.offsetTop : 0) + "px", height: (vv ? vv.height : window.innerHeight) + "px" });
    };
    fit();
    const phone = window.innerWidth <= 640;
    const prevOverflow = document.body.style.overflow;
    if (phone) document.body.style.overflow = "hidden";
    vv?.addEventListener("resize", fit);
    vv?.addEventListener("scroll", fit);
    window.addEventListener("resize", fit);
    return () => {
      vv?.removeEventListener("resize", fit);
      vv?.removeEventListener("scroll", fit);
      window.removeEventListener("resize", fit);
      if (phone) document.body.style.overflow = prevOverflow;
    };
  }, [isOpen]);

  const setTitle = () => {
    if (!baseTitle.current) baseTitle.current = document.title.replace(/^\(\d+\)\s*/, "");
    document.title = unseen.current ? `(${unseen.current}) ${baseTitle.current}` : baseTitle.current;
  };

  const dismiss = useCallback((key: string) => {
    clearTimeout(toastTimers.current[key]);
    delete toastTimers.current[key];
    setToasts((list) => list.filter((x) => x.key !== key));
  }, []);

  const armTimer = useCallback(
    (key: string) => {
      clearTimeout(toastTimers.current[key]);
      toastTimers.current[key] = setTimeout(() => dismiss(key), SHOW_MS);
    },
    [dismiss],
  );

  const handle = useCallback(
    (items: any[]) => {
      const ids = [...new Set(items.map((i) => i.conversation_id))];
      window.dispatchEvent(new CustomEvent("lbz:chat-incoming", { detail: { conversation_ids: ids, items } }));

      const open = winRef.current;
      let popped = 0;
      let intoWin = false;
      const fresh: Toast[] = [];
      items.forEach((i) => {
        if (i.from_staff) return; // the customer's own message from another tab/device
        if (open && open.conversationId === i.conversation_id) {
          const mapped = mapMessages([i.message]);
          setMessages((prev) => (prev.some((m) => m.id === mapped[0]?.id) ? prev : [...prev, ...mapped]));
          intoWin = true;
          return;
        }
        // Customer is already reading that thread (support slide-over / order chat).
        if (!document.hidden && String((window as any).lbzActiveChatId || "") === String(i.conversation_id)) return;
        const m = i.message || {};
        fresh.push({
          key: `c${i.conversation_id}-${m.id}`,
          conversationId: i.conversation_id,
          sender: i.sender_name || i.title || "",
          title: i.title || i.sender_name || "",
          avatar: i.avatar || null,
          sub: [i.title !== i.sender_name ? i.title : "", i.order_number || ""].filter(Boolean).join(" · "),
          text: m.message || attachmentLabel(m.attachment_type),
          time: clock(m.created_at),
          more: 1,
        });
        popped++;
      });
      if (fresh.length) {
        setToasts((list) => {
          let next = [...list];
          fresh.forEach((f) => {
            const old = next.find((x) => x.conversationId === f.conversationId);
            if (old) {
              next = next.map((x) => (x === old ? { ...old, text: f.text, time: f.time, sender: f.sender, more: old.more + 1 } : x));
              armTimer(old.key);
            } else {
              next.push(f);
              armTimer(f.key);
            }
          });
          return next.slice(-3);
        });
      }
      if (popped || (intoWin && document.hidden)) {
        beep(audioCtx);
        if (document.hidden) {
          unseen.current += popped || 1;
          setTitle();
        }
      }
    },
    [armTimer],
  );

  const poll = useCallback(async () => {
    if (stopped.current || busy.current) return;
    busy.current = true;
    try {
      const res = await getChatPoll({ after_id: lastId.current });
      const d = res?.data || {};
      const first = !lastId.current;
      if (d.last_id) lastId.current = Math.max(lastId.current, Number(d.last_id));
      if (!first && Array.isArray(d.items) && d.items.length) handle(d.items);
    } catch {
      /* offline / logged out — try again next tick */
    } finally {
      busy.current = false;
      if (!stopped.current) {
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(poll, document.hidden ? SLOW : FAST);
      }
    }
  }, [handle]);

  useEffect(() => {
    stopped.current = false;
    poll();
    const onVis = () => {
      if (!document.hidden) {
        unseen.current = 0;
        setTitle();
      }
      if (timer.current) clearTimeout(timer.current);
      poll();
    };
    // Other chat screens can ask for an immediate check (e.g. right after sending).
    const onNow = () => {
      if (timer.current) clearTimeout(timer.current);
      poll();
    };
    document.addEventListener("visibilitychange", onVis);
    window.addEventListener("lbz:chat-poll-now", onNow);
    return () => {
      stopped.current = true;
      if (timer.current) clearTimeout(timer.current);
      Object.values(toastTimers.current).forEach(clearTimeout);
      document.removeEventListener("visibilitychange", onVis);
      window.removeEventListener("lbz:chat-poll-now", onNow);
      unseen.current = 0;
      setTitle();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const openWin = async (tst: Toast) => {
    dismiss(tst.key);
    const w = { conversationId: tst.conversationId, title: tst.title, sub: tst.sub, avatar: tst.avatar };
    setWin(w);
    setMessages([]);
    setLoading(true);
    try {
      const res = await getChatMessages({ conversation_id: tst.conversationId });
      if (res?.status === 1) {
        setMessages(mapMessages(res.data?.messages));
        const c = res.data?.conversation;
        if (c?.title) setWin((cur) => (cur && cur.conversationId === w.conversationId ? { ...cur, title: c.title } : cur));
      }
    } catch {
      /* keep the window open; the next poll fills it */
    } finally {
      setLoading(false);
    }
  };

  const send = async ({ text, images, audios, videos, files }: ChatInputSendArgs) => {
    const w = winRef.current;
    if (!w) return;
    if (!text?.trim() && !images?.length && !audios?.length && !videos?.length && !files?.length) return;
    setSending(true);
    try {
      const res = await sendChatMessage({ conversation_id: w.conversationId, message: text?.trim() || "", images, audios, videos, files });
      if (res?.status === 1) {
        const list = Array.isArray(res.data) ? res.data : res.data ? [res.data] : [];
        const created = mapMessages(list);
        setMessages((prev) => [...prev, ...created.filter((c) => !prev.some((p) => p.id === c.id))]);
        window.dispatchEvent(new CustomEvent("lbz:chat-incoming", { detail: { conversation_ids: [w.conversationId], items: [] } }));
      } else if (res?.message) {
        toast.error(res.message);
      }
    } catch (e: any) {
      toast.error(e?.response?.data?.message || e?.message || "Could not send");
    } finally {
      setSending(false);
    }
  };

  if (typeof document === "undefined") return null;
  return createPortal(
    <>
      <div
        aria-live="polite"
        className={`fixed right-4 sm:right-5 z-[90] flex w-[340px] max-w-[calc(100vw-32px)] flex-col gap-2.5 pointer-events-none ${
          win ? "bottom-[calc(min(520px,85vh)+28px)] max-sm:hidden" : "bottom-[calc(80px+env(safe-area-inset-bottom))] md:bottom-5"
        }`}
      >
        {toasts.map((tst) => (
          <div
            key={tst.key}
            role="button"
            tabIndex={0}
            onClick={() => openWin(tst)}
            onKeyDown={(e) => e.key === "Enter" && openWin(tst)}
            onMouseEnter={() => clearTimeout(toastTimers.current[tst.key])}
            onMouseLeave={() => armTimer(tst.key)}
            className="pointer-events-auto relative flex cursor-pointer items-start gap-2.5 rounded-xl border border-gray-200 border-l-4 bg-white p-3 pr-9 text-gray-900 shadow-xl transition hover:-translate-y-0.5 dark:border-white/10 dark:bg-zinc-900 dark:text-gray-100 animate-in slide-in-from-right-8 fade-in duration-200"
            style={{ borderLeftColor: "var(--primary-color, currentColor)" }}
          >
            <div className="primaryBackColor flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full text-sm font-bold text-white">
              {tst.avatar ? <img src={tst.avatar} alt="" className="h-full w-full object-cover" /> : initials(tst.sender)}
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1.5">
                <strong className="truncate text-sm">{tst.sender}</strong>
                {tst.more > 1 && <span className="rounded-full bg-red-600 px-1.5 text-[11px] leading-[17px] text-white">{tst.more}</span>}
                <small className="ml-auto whitespace-nowrap text-[11px] opacity-60">{tst.time}</small>
              </div>
              {tst.sub && <div className="truncate text-[11.5px] opacity-65">{tst.sub}</div>}
              <div className="mt-0.5 line-clamp-2 break-words text-[13px]">{tst.text}</div>
              <div className="primaryColor mt-1 text-[11px] font-semibold">{t("tap_to_reply") || "Tap to reply"}</div>
            </div>
            <button
              type="button"
              aria-label={t("close") || "Close"}
              onClick={(e) => {
                e.stopPropagation();
                dismiss(tst.key);
              }}
              className="absolute right-2 top-2 p-0.5 opacity-50 hover:opacity-100"
            >
              <LuX size={16} />
            </button>
          </div>
        ))}
      </div>

      {win && (
        <div
          role="dialog"
          style={fitStyle}
          className="fixed inset-x-0 top-0 z-[91] flex h-[100dvh] w-full flex-col overflow-hidden border-gray-200 bg-white shadow-2xl dark:border-white/10 dark:bg-zinc-900 sm:inset-x-auto sm:top-auto sm:bottom-5 sm:right-5 sm:h-[min(520px,85vh)] sm:w-[370px] sm:rounded-2xl sm:border animate-in slide-in-from-bottom-6 fade-in duration-200 [&_textarea]:text-base [&_input]:text-base sm:[&_textarea]:text-sm"
        >
          <header className="primaryBackColor flex items-center gap-2.5 px-3 py-2.5 pt-[calc(10px+env(safe-area-inset-top))] sm:pt-2.5 text-white">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white/20 text-xs font-bold">
              {win.avatar ? <img src={win.avatar} alt="" className="h-full w-full object-cover" /> : <LuMessageCircle size={18} />}
            </div>
            <div className="min-w-0 flex-1 leading-tight">
              <p className="truncate text-sm font-semibold">{win.title || t("support") || "Support"}</p>
              {win.sub && <p className="truncate text-[11.5px] opacity-80">{win.sub}</p>}
            </div>
            <button
              type="button"
              aria-label={t("close") || "Close"}
              onClick={() => setWin(null)}
              className="flex h-8 w-8 items-center justify-center rounded-full bg-white/20 hover:bg-white/30"
            >
              <LuX size={18} />
            </button>
          </header>
          <MessageList messages={messages} empty={loading ? t("loading") || "Loading…" : undefined} />
          <div className="pb-[env(safe-area-inset-bottom)] sm:pb-0">
            <ChatInput onSend={send} disabled={loading || sending} placeholder={t("type_a_message") || "Type a message…"} />
          </div>
        </div>
      )}
    </>,
    document.body,
  );
}
