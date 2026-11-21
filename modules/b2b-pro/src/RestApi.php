<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

final class RestApi
{
    private const MESSAGES = [
        'authentication_required' => ['Sign in to continue.', 'চালিয়ে যেতে সাইন ইন করুন।'],
        'nonce_invalid' => ['Refresh the page and try again.', 'পৃষ্ঠা রিফ্রেশ করে আবার চেষ্টা করুন।'],
        'forbidden' => ['Your account cannot perform this action.', 'আপনার অ্যাকাউন্টে এই কাজের অনুমতি নেই।'],
        'request_invalid' => ['Check the request fields.', 'অনুরোধের ঘরগুলো যাচাই করুন।'],
        'idempotency_key_invalid' => ['Supply a valid request key.', 'বৈধ অনুরোধ কী দিন।'],
        'idempotency_conflict' => ['This key already belongs to different request data.', 'এই কী আগে অন্য অনুরোধে ব্যবহার হয়েছে।'],
        'input_too_large' => ['The CSV is too large.', 'CSV ফাইলটি অতিরিক্ত বড়।'],
        'too_many_rows' => ['The CSV has too many rows.', 'CSV-তে অতিরিক্ত সারি আছে।'],
        'columns_invalid' => ['Use SKU, quantity and optional unit.', 'SKU, পরিমাণ এবং প্রয়োজনে একক দিন।'],
        'sku_invalid' => ['Enter a valid SKU.', 'বৈধ SKU লিখুন।'],
        'qty_invalid' => ['Enter a positive whole quantity.', 'ধনাত্মক পূর্ণসংখ্যায় পরিমাণ দিন।'],
        'unit_invalid' => ['Select a unit supported by this product.', 'পণ্যের জন্য নির্ধারিত একক বেছে নিন।'],
        'sku_not_found' => ['This SKU is unavailable.', 'এই SKU পাওয়া যাচ্ছে না।'],
        'not_purchasable' => ['This product is unavailable for purchase.', 'এই পণ্য কেনার জন্য পাওয়া যাচ্ছে না।'],
        'quantity_rule' => ['Check the minimum, maximum, and quantity step.', 'সর্বনিম্ন, সর্বোচ্চ এবং পরিমাণের ধাপ যাচাই করুন।'],
        'below_minimum' => ['Quantity is below the minimum.', 'পরিমাণ সর্বনিম্ন সীমার নিচে।'],
        'above_maximum' => ['Quantity exceeds the maximum.', 'পরিমাণ সর্বোচ্চ সীমার বেশি।'],
        'step_mismatch' => ['Quantity does not match the required step.', 'পরিমাণ নির্ধারিত ধাপের সঙ্গে মেলে না।'],
        'insufficient_stock' => ['There is insufficient stock.', 'পর্যাপ্ত স্টক নেই।'],
        'sku_duplicate' => ['List each product once.', 'প্রতিটি পণ্য একবার তালিকাভুক্ত করুন।'],
        'currency_mismatch' => ['Currencies must match.', 'মুদ্রা একই হতে হবে।'],
        'currency_precision_unsupported' => ['This module requires two currency decimal places.', 'এই মডিউলে মুদ্রার জন্য দুই দশমিক ঘর দরকার।'],
        'money_overflow' => ['The amount is above the supported limit.', 'পরিমাণ সমর্থিত সীমার বেশি।'],
        'quote_not_found' => ['Quote unavailable.', 'কোট পাওয়া যাচ্ছে না।'],
        'not_quote_owner' => ['Quote unavailable to this buyer.', 'এই ক্রেতার জন্য কোট পাওয়া যাচ্ছে না।'],
        'not_quote_vendor' => ['Quote unavailable to this vendor.', 'এই বিক্রেতার জন্য কোট পাওয়া যাচ্ছে না।'],
        'vendor_mismatch' => ['A quote must use products from one assigned vendor.', 'একটি কোটে নির্ধারিত একজন বিক্রেতার পণ্য থাকতে হবে।'],
        'transition_not_allowed' => ['This action is unavailable at the current quote stage.', 'কোটের বর্তমান ধাপে এই কাজ করা যাবে না।'],
        'role_not_allowed' => ['Your role cannot perform this action.', 'আপনার ভূমিকায় এই কাজের অনুমতি নেই।'],
        'offer_expired' => ['The offer has expired.', 'অফারের মেয়াদ শেষ হয়েছে।'],
        'valid_until_past' => ['Choose a future expiry time.', 'মেয়াদ শেষের জন্য ভবিষ্যতের সময় বেছে নিন।'],
        'lines_invalid' => ['Provide between one and 50 lines.', 'এক থেকে ৫০টি লাইন দিন।'],
        'lines_empty' => ['Add at least one product.', 'অন্তত একটি পণ্য যোগ করুন।'],
        'line_invalid' => ['Check the quote line details.', 'কোটের লাইনের তথ্য যাচাই করুন।'],
        'offer_lines_empty' => ['Add at least one offer line.', 'অন্তত একটি অফার লাইন যোগ করুন।'],
        'offer_line_invalid' => ['Check the offer quantity and unit price.', 'অফারের পরিমাণ এবং একক মূল্য যাচাই করুন।'],
        'credit_account_missing' => ['A credit account is required.', 'একটি ক্রেডিট অ্যাকাউন্ট প্রয়োজন।'],
        'credit_limit_exceeded' => ['The order exceeds your available credit.', 'অর্ডারের পরিমাণ অবশিষ্ট ক্রেডিটের বেশি।'],
        'overdue_invoices' => ['There are overdue invoices.', 'কিছু ইনভয়েসের পরিশোধের মেয়াদ পেরিয়েছে।'],
        'amount_invalid' => ['Enter a valid positive amount.', 'বৈধ ধনাত্মক পরিমাণ লিখুন।'],
        'settlement_invalid' => ['Enter a positive amount and a valid reference.', 'ধনাত্মক পরিমাণ ও বৈধ রেফারেন্স দিন।'],
        'overpayment' => ['This amount exceeds the open balance.', 'পরিমাণ বাকি পাওনার চেয়ে বেশি।'],
        'settlement_reference_exists' => ['This payment reference already exists.', 'এই পেমেন্ট রেফারেন্স আগে ব্যবহার হয়েছে।'],
        'credit_unavailable' => ['This order line is unavailable for a credit note.', 'এই অর্ডার লাইনে ক্রেডিট নোট দেওয়া যাবে না।'],
        'fulfillment_unavailable' => ['This credit order does not have a valid open stock hold.', 'এই ক্রেডিট অর্ডারের বৈধ স্টক হোল্ড নেই।'],
        'hold_not_found' => ['No hold was found for this buyer and order.', 'এই ক্রেতা ও অর্ডারের জন্য হোল্ড পাওয়া যায়নি।'],
        'hold_not_expired' => ['The hold has not expired or its expiry was not recorded.', 'হোল্ডের মেয়াদ শেষ হয়নি অথবা মেয়াদ রেকর্ড করা হয়নি।'],
        'hold_requires_review' => ['Review this order before releasing its credit charge.', 'ক্রেডিট চার্জ ছাড়ার আগে অর্ডারটি পর্যালোচনা করুন।'],
        'database_unavailable' => ['The operation could not be confirmed. Retry with the same key.', 'কাজটি নিশ্চিত করা যায়নি। একই কী দিয়ে আবার চেষ্টা করুন।'],
    ];

    public function __construct(private QuickOrderCsv $csv, private WooQuotes $quotes) {}

    public function register(): void
    {
        foreach (['quick-order', 'submit', 'offer', 'revise', 'accept', 'reject', 'cancel', 'convert', 'settle', 'reconcile', 'fulfill', 'credit'] as $action) {
            register_rest_route('sfm4u-b2b/v1', '/' . $action, [
                'methods' => 'POST',
                'callback' => fn(\WP_REST_Request $request) => $this->dispatch($request, $action),
                'permission_callback' => fn(\WP_REST_Request $request) => $this->permission($request, $action),
            ]);
        }
    }

    public function permission(\WP_REST_Request $request, string $action): bool|\WP_Error
    {
        if (!get_current_user_id()) {
            return self::error('authentication_required', 401);
        }
        if (!wp_verify_nonce((string) $request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return self::error('nonce_invalid', 403);
        }
        $buyer = current_user_can('sfm4u_b2b_buy');
        $vendor = current_user_can('sfm4u_b2b_offer');
        $allowed = match ($action) {
            'offer' => $vendor,
            'settle', 'reconcile', 'fulfill', 'credit' => current_user_can('sfm4u_b2b_settle'),
            'reject' => $buyer || $vendor,
            default => $buyer,
        };
        return $allowed ? true : self::error('forbidden', 403);
    }

    private function dispatch(\WP_REST_Request $request, string $action): array|\WP_Error
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return self::error('request_invalid', 422);
        }
        $actor = get_current_user_id();
        if ($action === 'quick-order') {
            if (!is_string($data['csv'] ?? null)) {
                return self::error('request_invalid', 422);
            }
            try {
                $result = $this->csv->parse($data['csv'], (string) $actor);
                foreach ($result['errors'] as &$row) {
                    $row['messages'] = self::messages($row['code']);
                }
                return $result;
            } catch (\Throwable $e) {
                return $this->failure($e);
            }
        }
        $key = (string) $request->get_header('Idempotency-Key');
        try {
            if (in_array($action, ['settle', 'reconcile', 'fulfill', 'credit'], true)) {
                $buyer = $data['buyer'] ?? null;
                if (!is_int($buyer) || $buyer < 1) {
                    return self::error('request_invalid', 422);
                }
                if ($action === 'settle' && is_int($data['amount_minor'] ?? null) && is_string($data['reference'] ?? null)) {
                    return $this->quotes->settle($actor, $buyer, $key, $data['amount_minor'], $data['reference']);
                }
                if ($action === 'credit' && is_int($data['order'] ?? null) && $data['order'] > 0
                    && is_int($data['line'] ?? null) && $data['line'] > 0
                    && is_int($data['quantity'] ?? null) && is_bool($data['resellable'] ?? null)) {
                    return $this->quotes->credit($actor, $buyer, $data['order'], $data['line'], $data['quantity'], $data['resellable'], $key);
                }
                if ($action === 'fulfill' && is_int($data['order'] ?? null) && $data['order'] > 0) {
                    return $this->quotes->fulfill($actor, $buyer, $data['order'], $key);
                }
                if ($action === 'reconcile' && is_int($data['order'] ?? null) && $data['order'] > 0) {
                    return $this->quotes->reconcile($actor, $buyer, $data['order'], $key);
                }
                return self::error('request_invalid', 422);
            }
            if ($action === 'submit' && is_array($data['lines'] ?? null)) {
                return $this->quotes->submit($actor, $key, $data['lines']);
            }
            $id = $data['id'] ?? null;
            if (!is_string($id) || !wp_is_uuid($id, 4)) {
                return self::error('request_invalid', 422);
            }
            if ($action === 'convert') {
                return $this->quotes->convert($actor, $id, $key);
            }
            $role = $action === 'offer' || ($action === 'reject' && ($data['role'] ?? '') === 'seller') ? 'seller' : 'buyer';
            if (!current_user_can($role === 'seller' ? 'sfm4u_b2b_offer' : 'sfm4u_b2b_buy')) {
                return self::error('forbidden', 403);
            }
            $payload = $action === 'offer' ? ['offer_lines' => $data['lines'] ?? null, 'valid_until' => $data['valid_until'] ?? null] : [];
            return $this->quotes->change($actor, $role, $id, $key, $action, $payload);
        } catch (\Throwable $error) {
            return $this->failure($error);
        }
    }

    private function failure(\Throwable $error): \WP_Error
    {
        $code = explode(':', $error->getMessage(), 2)[0];
        if ($error->getCode() === 409) {
            $code = 'idempotency_conflict';
        }
        if (!isset(self::MESSAGES[$code])) {
            $code = 'database_unavailable';
        }
        $status = match ($code) {
            'database_unavailable' => 503,
            'idempotency_conflict', 'transition_not_allowed' => 409,
            'quote_not_found', 'not_quote_owner', 'not_quote_vendor', 'hold_not_found' => 404,
            'role_not_allowed', 'forbidden' => 403,
            default => 422,
        };
        return self::error($code, $status);
    }

    public static function messageForLocale(string $code, string $locale): string
    {
        return self::messages($code)[str_starts_with($locale, 'bn') ? 'bn' : 'en'];
    }

    private static function messages(string $code): array
    {
        [$en, $bn] = self::MESSAGES[$code] ?? self::MESSAGES['database_unavailable'];
        return compact('en', 'bn');
    }

    private static function error(string $code, int $status): \WP_Error
    {
        $messages = self::messages($code);
        return new \WP_Error($code, $messages[determine_locale() === 'bn_BD' ? 'bn' : 'en'], [
            'status' => $status, 'messages' => $messages,
        ]);
    }
}
