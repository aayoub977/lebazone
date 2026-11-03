# WhatsApp message templates for Hader

WhatsApp only lets businesses start a conversation with **pre-approved templates**.
Create each template below in **WhatsApp Manager → Message templates**, using the exact **name** shown.
Hader fills `{{1}}`, `{{2}}`… automatically, in the order listed.

- Category: **Utility** for order/return/wallet updates, **Authentication** for `otp_customer` (choose "Copy code" button).
- Language: create **English** first; add **Arabic** versions with the same name later (set *Template languages* to `en,ar` in admin → SMS settings).
- Cart reminders: also switch on the SMS channel for *Cart reminder* events in admin → Notification settings.
- Templates not created yet simply fall back to SMS (if an SMS gateway is on).

Start with the starred ones — they cover most messages customers get.

| # | Template name | Body text to submit | Variables |
|---|---|---|---|
| 1 | ⭐ `otp_customer` | *(Authentication template — WhatsApp writes the text: "{{1}} is your verification code." Tick "Add security recommendation" and choose a **Copy code** button.)* | 1=otp |
| 2 | `order_status_payment_pending_customer` | Hi {{1}}, your order #{{2}} is placed and payment is pending. | 1=customer_name, 2=order_id |
| 3 | ⭐ `order_status_received_customer` | Hi {{1}}, your order #{{2}} has been received. Total: {{3}}{{4}}. | 1=customer_name, 2=order_id, 3=currency, 4=final_total |
| 4 | `order_status_processed_customer` | Your order #{{1}} is being processed. | 1=order_id |
| 5 | ⭐ `order_status_shipped_customer` | Your order #{{1}} has been shipped. | 1=order_id |
| 6 | ⭐ `order_status_out_for_delivery_customer` | Your order #{{1}} is out for delivery. | 1=order_id |
| 7 | ⭐ `order_status_delivered_customer` | Your order #{{1}} has been delivered. Thank you for shopping with {{2}}! | 1=order_id, 2=app_name |
| 8 | ⭐ `order_status_cancelled_customer` | Your order #{{1}} has been cancelled. | 1=order_id |
| 9 | `order_status_returned_customer` | Your order #{{1}} has been returned. | 1=order_id |
| 10 | `order_status_preparing_customer` | Your order #{{1}} is being prepared. | 1=order_id |
| 11 | `order_status_ready_for_pickup_customer` | Your order #{{1}} is ready for pickup at {{2}}, {{3}}. | 1=order_id, 2=store_name, 3=store_address |
| 12 | `order_status_picked_up_customer` | Your order #{{1}} has been picked up. | 1=order_id |
| 13 | `return_status_return_requested_customer` | Your return request #{{1}} for order #{{2}} has been submitted. | 1=return_request_id, 2=order_id |
| 14 | `return_status_accepted_customer` | Your return request #{{1}} has been accepted. | 1=return_request_id |
| 15 | `return_status_rejected_customer` | Your return request #{{1}} has been rejected. | 1=return_request_id |
| 16 | `return_status_out_for_pickup_customer` | Your return #{{1}} is out for pickup. | 1=return_request_id |
| 17 | `return_status_received_from_customer_customer` | Your return #{{1}} has been picked up. | 1=return_request_id |
| 18 | `return_status_return_to_store_customer` | Your return #{{1}} has reached the store. | 1=return_request_id |
| 19 | `return_status_refund_completed_customer` | Refund for your return #{{1}} is completed. | 1=return_request_id |
| 20 | `order_item_status_customer` | {{1}} in order #{{2}} is now {{3}}. - {{4}} | 1=product_name, 2=order_id, 3=status_name, 4=app_name |
| 21 | `order_item_delivery_estimate_customer` | {{1}} in order #{{2}} is now expected by {{3}}. - {{4}} | 1=product_name, 2=order_id, 3=estimated_delivery_date, 4=app_name |
| 22 | `order_item_cancelled_customer` | {{1}} in order #{{2}} has been cancelled. - {{3}} | 1=product_name, 2=order_id, 3=app_name |
| 23 | `order_item_returned_customer` | {{1}} in order #{{2}} has been returned. - {{3}} | 1=product_name, 2=order_id, 3=app_name |
| 24 | `wallet_recharged_customer` | {{1}}{{2}} added to your wallet. Balance: {{1}}{{3}}. - {{4}} | 1=currency, 2=amount, 3=balance, 4=app_name |
| 25 | `wallet_refund_cancelled_customer` | Refund of {{1}}{{2}} for cancelled item in order #{{3}} credited to your wallet. Balance: {{1}}{{4}}. - {{5}} | 1=currency, 2=amount, 3=order_id, 4=balance, 5=app_name |
| 26 | `wallet_refund_returned_customer` | Refund of {{1}}{{2}} for returned item in order #{{3}} credited to your wallet. Balance: {{1}}{{4}}. - {{5}} | 1=currency, 2=amount, 3=order_id, 4=balance, 5=app_name |
| 27 | `wallet_cashback_customer` | You earned {{1}}{{2}} cashback on order #{{3}}. Balance: {{1}}{{4}}. - {{5}} | 1=currency, 2=amount, 3=order_id, 4=balance, 5=app_name |
| 28 | `wallet_referral_bonus_customer` | You earned a referral bonus of {{1}}{{2}}. Balance: {{1}}{{3}}. - {{4}} | 1=currency, 2=amount, 3=balance, 4=app_name |
| 29 | `wallet_admin_credit_customer` | {{1}}{{2}} credited to your wallet. Balance: {{1}}{{3}}. - {{4}} | 1=currency, 2=amount, 3=balance, 4=app_name |
| 30 | `back_in_stock_customer` | {{1}} ({{2}}) is back in stock at {{3}}{{4}}. Order now! - {{5}} | 1=product_name, 2=variant_name, 3=currency, 4=price, 5=app_name |
| 31 | `wallet_recharge_failed_customer` | Your wallet recharge of {{1}}{{2}} could not be completed. Please try again. - {{3}} | 1=currency, 2=amount, 3=app_name |
| 32 | `account_status_customer` | Your {{1}} account is now {{2}}. | 1=app_name, 2=status_name |
| 33 | `payment_failed_customer` | Payment of {{1}}{{2}} for order #{{3}} failed and the order was cancelled. - {{4}} | 1=currency, 2=amount, 3=order_id, 4=app_name |
| 34 | `promo_code_customer` | Use code {{1}} to get {{2}} off. Valid till {{3}}. - {{4}} | 1=promo_code, 2=discount, 3=expiry_date, 4=app_name |

| 35 | ⭐ `cart_reminder_first_customer` | Hi! {{1}} is still in your {{2}} cart. Complete your order before it sells out. | 1=product_name, 2=app_name |
| 36 | `cart_reminder_interval_customer` | Reminder: {{1}} is waiting in your {{2}} cart. Order now and don't miss out! | 1=product_name, 2=app_name |

Delivery-partner and admin messages (`*_delivery_boy`, `*_admin`) use the same scheme if you want them on WhatsApp too.
