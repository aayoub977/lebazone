<?php

namespace App\Services\Payments;

use App\Helpers\CommonHelper;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusList;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Custom (Hader): marks a verified Whish / Areeba payment as paid (order or wallet
 * recharge) or failed. Mirrors the vendor's PayPal settlement, parameterised by
 * gateway, so every gateway ends an order in exactly the same state.
 * Idempotent on the gateway transaction id.
 */
class LocalPaymentSettlement
{
    public static function alreadySettled(string $customId): bool
    {
        if ($customId === '') {
            return false;
        }
        if (is_numeric($customId)) {
            $order = Order::find((int) $customId);
            return $order && (int) $order->active_status === (int) OrderStatusList::$received;
        }
        return WalletTransaction::where('order_id', $customId)->where('status', Transaction::$statusSuccess)->exists();
    }

    public static function success(string $customId, string $txnId, float $amount, string $paymentType, string $txnType): void
    {
        if ($customId === '' || $txnId === '') {
            return;
        }
        if (Transaction::where('txn_id', $txnId)->exists() || WalletTransaction::where('txn_id', $txnId)->exists()) {
            return; // duplicate callback
        }

        // --- wallet recharge ---
        if (!is_numeric($customId) && str_contains($customId, 'wallet_recharge')) {
            $parts = explode('-', $customId);
            $userId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
            if (!$userId) {
                return;
            }
            $meta = CommonHelper::rechargeWalletMeta(CommonHelper::parseRechargeCountryId($customId), $userId);
            $wt = WalletTransaction::create([
                'payment_type' => $paymentType, 'user_id' => $userId, 'order_id' => $customId, 'type' => 'credit',
                'txn_id' => $txnId, 'payu_txn_id' => '', 'amount' => $amount, 'status' => Transaction::$statusSuccess,
                'message' => 'wallet_successfully_recharged', 'transaction_date' => date('Y-m-d H:i:s'),
                'country_id' => $meta['country_id'], 'currency' => $meta['currency'], 'currency_code' => $meta['currency_code'],
            ]);
            CommonHelper::addUserWalletBalance($amount, $userId, $meta['country_id']);
            CommonHelper::notifyWalletRecharge($userId, $amount, $meta['country_id'], $wt->txn_id ?? null);
            return;
        }

        // --- order ---
        $order = Order::find((int) $customId);
        if (!$order || (int) $order->active_status === (int) OrderStatusList::$received) {
            return;
        }
        $transaction = Transaction::create([
            'user_id' => $order->user_id, 'order_id' => $order->id, 'type' => $txnType, 'txn_id' => $txnId,
            'payu_txn_id' => '', 'amount' => $amount ?: $order->final_total, 'status' => Transaction::$statusSuccess,
            'message' => 'txn_order_payment', 'transaction_date' => date('Y-m-d H:i:s'),
        ]);
        $order->active_status = OrderStatusList::$received;
        $order->transaction_id = $transaction->id ?? 0;

        if (isset($order->wallet_balance) && $order->wallet_balance > 0) {
            $user = User::find($order->user_id);
            if ($user) {
                $newBalance = $user->balance < $order->wallet_balance ? 0 : $user->balance - $order->wallet_balance;
                CommonHelper::updateUserWalletBalance($newBalance, $user->id);
                CommonHelper::addWalletTransaction($order->id, 0, $user->id, 'debit', $order->wallet_balance, 'wallet_used_against_order_placement', 1, $paymentType);
            }
        }
        $order->save();

        OrderItem::where('order_id', $order->id)
            ->whereNotIn('active_status', [OrderStatusList::$cancelled, OrderStatusList::$returned])
            ->update(['active_status' => $order->active_status]);

        CommonHelper::finalizeOnlineOrderSuccess($order);

        try {
            dispatch(function () use ($order) {
                CommonHelper::sendNotificationOrderStatus($order);
                CommonHelper::sendSmsOrderStatus($order, $order->active_status);
                CommonHelper::sendOrderNotificationsToAdmins($order, 'new_order', $order->delivery_boy_id ?? null);
            })->afterResponse();
        } catch (\Throwable $e) {
            Log::error("{$paymentType} order notification error: " . $e->getMessage());
        }
    }

    public static function failure(string $customId, string $txnId, float $amount, string $paymentType, string $txnType): void
    {
        if ($customId === '' || self::alreadySettled($customId)) {
            return; // never undo a settled payment
        }
        try {
            if (!is_numeric($customId) && str_contains($customId, 'wallet_recharge')) {
                $parts = explode('-', $customId);
                $userId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
                if (!$userId || ($txnId !== '' && WalletTransaction::where('txn_id', $txnId)->exists())) {
                    return;
                }
                $meta = CommonHelper::rechargeWalletMeta(CommonHelper::parseRechargeCountryId($customId), $userId);
                WalletTransaction::create([
                    'user_id' => $userId, 'order_id' => '', 'type' => 'credit', 'payment_type' => $paymentType,
                    'txn_id' => $txnId, 'amount' => round($amount, 2), 'status' => WalletTransaction::$statusFailed,
                    'message' => 'wallet_recharge_failed', 'transaction_date' => date('Y-m-d H:i:s'),
                    'country_id' => $meta['country_id'], 'currency' => $meta['currency'], 'currency_code' => $meta['currency_code'],
                ]);
                return;
            }

            $order = Order::find((int) $customId);
            if (!$order) {
                return;
            }
            $transaction = Transaction::create([
                'user_id' => $order->user_id, 'order_id' => $order->id, 'type' => $txnType, 'txn_id' => $txnId,
                'payu_txn_id' => '', 'amount' => $order->total, 'status' => Transaction::$statusFailed,
                'message' => 'txn_payment_failed', 'transaction_date' => date('Y-m-d H:i:s'),
            ]);
            $order->transaction_id = $transaction->id ?? $order->transaction_id;
            $order->active_status = OrderStatusList::$cancelled;
            $order->promo_code_id = 0;
            $order->save();
            CommonHelper::reverseOrderTax($order);
            dispatch(fn () => CommonHelper::sendPaymentFailedNotification($order))->afterResponse();
        } catch (\Throwable $e) {
            Log::error("{$paymentType} failure settlement error: " . $e->getMessage());
        }
    }
}
