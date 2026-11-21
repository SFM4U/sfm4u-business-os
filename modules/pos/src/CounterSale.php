<?php
declare(strict_types=1);

namespace SFM4U\POS;

use DomainException;
use RuntimeException;
use SFM4U\Inventory\{ManagedOrderStock, RequestJournal};
use SFM4U\Wholesale\{Commerce, MemberPrice, Rule};
use SFM4U\Wholesale\Members\Members;

final class CounterSale
{
    public const METHODS = ['cash' => 'Cash', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'card' => 'Card'];

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'cash' => __('Cash', 'sfm4u-wholesale'),
            'bkash' => __('bKash', 'sfm4u-wholesale'),
            'nagad' => __('Nagad', 'sfm4u-wholesale'),
            'card' => __('Card', 'sfm4u-wholesale'),
        };
    }

    public static function intent(array $lines, string $tier, int $discount, string $method, string $member = ''): array
    {
        $member = trim($member);
        if (($tier === 'member') !== ($member !== '') || strlen($member) > 200) {
            throw new DomainException('Scan the member card for the member price.', 422);
        }
        if (!array_is_list($lines) || !$lines || count($lines) > 30 || !in_array($tier, ['retail', 'wholesale', 'member'], true)
            || !isset(self::METHODS[$method]) || $discount < 0 || $discount > 1000000000) {
            throw new DomainException('Invalid counter cart.', 422);
        }
        $items = [];
        foreach ($lines as $line) {
            if (!is_array($line) || !is_int($line['product'] ?? null) || !is_int($line['quantity'] ?? null)) {
                throw new DomainException('Invalid counter cart line.', 422);
            }
            $product = $line['product'];
            $quantity = $line['quantity'];
            if ($product < 1 || $quantity < 1 || $quantity > 10000 || isset($items[$product])) {
                throw new DomainException('Invalid or duplicate counter cart line.', 422);
            }
            $items[$product] = $quantity;
        }
        ksort($items, SORT_NUMERIC);
        // 'member' is only present for member sales, so older queued intents keep their digest.
        return compact('items', 'tier', 'discount', 'method') + ($tier === 'member' ? ['member' => $member] : []);
    }

    public static function quote(int $merchant, array $intent, bool $confirming = false, ?int &$memberUserId = null): array
    {
        if (!Runtime::allowedContext() || get_woocommerce_currency() !== 'BDT' || wc_get_price_decimals() !== 2 || wc_tax_enabled()) {
            throw new DomainException('Counter sales require untaxed BDT with two decimals.', 422);
        }
        $memberUserId = null;
        $subtotal = 0;
        $lines = [];
        $member = null;
        $floors = [];
        if ($intent['tier'] === 'member') {
            // Plan section 4 step 1 at the counter: the scanned card decides; checked again when the sale is confirmed.
            $member = MemberPrice::fromScan((string) $intent['member']);
            if ($confirming && (!$member || !$member['qualifies'])) {
                throw new DomainException('Membership changed. Scan the card again and review the cart.', 409);
            }
            if (!$member) {
                throw new DomainException('Card not found.', 404);
            }
            if (!$member['qualifies']) {
                throw new DomainException('This card does not get the member price. Only an active Business card or a paid Business Black card does.', 422);
            }
            $memberUserId = (int) ($member['user_id'] ?? 0) ?: null;
            if (get_option('sfmwh_enabled', 'no') !== 'yes') {
                throw new DomainException('Wholesale pricing is unavailable.', 422);
            }
        }
        foreach ($intent['items'] as $id => $quantity) {
            $product = wc_get_product($id);
            if (!$product || !RegisterProductSearch::eligible($product, $merchant)) {
                throw new DomainException('Counter product outside register scope.', 403);
            }
            $unit = null;
            if ($member) {
                $stored = Commerce::storedRule((int) $id);
                if (is_array($stored)) {
                    $rule = Rule::rescale(Rule::validate($stored), 2);
                    // Same member, same price online and at the counter: use the member's own buyer group when known.
                    $group = !empty($member['user_id']) ? MemberPrice::groupPrice($rule, (int) $member['user_id']) : null;
                    $quote = Rule::memberQuote($rule, $quantity, 'piece', $group, MemberPrice::priceMode());
                    $floors[count($lines)] = $rule['floor_minor'] ?? null;
                    if ($quote['currency'] !== 'BDT' || $quote['scale'] !== 2) {
                        throw new DomainException('Wholesale pricing currency is unavailable.', 422);
                    }
                    $unit = $quote['pack_minor'];
                }
                // A product without a wholesale rule stays at its retail price.
            } elseif ($intent['tier'] === 'wholesale') {
                $stored = Commerce::storedRule((int) $id);
                if (get_option('sfmwh_enabled', 'no') !== 'yes' || !is_array($stored)) {
                    throw new DomainException('Wholesale pricing is unavailable for a cart line.', 422);
                }
                $rule = Rule::rescale(Rule::validate($stored), 2);
                $quote = Rule::quote($rule, $quantity, 'piece');
                if ($quote !== null) {
                    $floors[count($lines)] = $rule['floor_minor'] ?? null;
                    if ($quote['currency'] !== 'BDT' || $quote['scale'] !== 2) {
                        throw new DomainException('Wholesale pricing currency is unavailable.', 422);
                    }
                    $unit = $quote['pack_minor'];
                }
                // A public rule below its wholesale quantity returns null: retail price applies.
            }
            if ($unit === null) {
                $price = (string) $product->get_price('edit');
                if (!preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/D', $price, $parts)) {
                    throw new DomainException('Invalid retail price.', 422);
                }
                $unit = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0', STR_PAD_RIGHT);
            }
            $amount = $unit * $quantity;
            $subtotal += $amount;
            $lines[] = ['product' => $id, 'quantity' => $quantity, 'unit_minor' => $unit, 'subtotal_minor' => $amount,
                'name' => $product->get_name(), 'sku' => $product->get_sku()];
        }
        if ($subtotal > 1000000000 || $intent['discount'] > $subtotal) {
            throw new DomainException('Invalid cart subtotal or discount.', 422);
        }
        $net = $subtotal - $intent['discount'];
        $rounding = 0;
        if ($intent['method'] === 'cash') {
            $increment = (string) get_option(SyntheticSale::CASH_ROUNDING_OPTION, '100');
            if (!in_array($increment, ['1', '5', '10', '25', '50', '100'], true)) {
                throw new DomainException('Invalid cash rounding denomination.', 422);
            }
            $increment = (int) $increment;
            $rounding = intdiv($net + intdiv($increment, 2), $increment) * $increment - $net;
        }
        $allocated = 0;
        $cumulative = 0;
        foreach ($lines as &$line) {
            $cumulative += $line['subtotal_minor'];
            $share = $subtotal > 0 ? intdiv($intent['discount'] * $cumulative, $subtotal) : 0;
            $line['total_minor'] = $line['subtotal_minor'] - ($share - $allocated);
            $allocated = $share;
        }
        unset($line);
        // Vendor floor also holds after the counter discount (Codex review 2026-11-21).
        foreach ($lines as $index => $line) {
            $floor = $floors[$index] ?? null;
            if ($floor !== null && $line['total_minor'] < $floor * $line['quantity']) {
                throw new DomainException(sprintf('The discount takes %s below the lowest price the shop accepts.', $line['name']), 422);
            }
        }
        $memberInfo = $member ? ['member_no' => MemberPrice::mask((string) $member['member_no']), 'tier' => (string) $member['tier'],
            'name' => (string) ($member['name'] ?? ''), 'business_name' => (string) ($member['business_name'] ?? ''), 'photo' => (string) ($member['photo'] ?? '')] : null;
        $digest = hash('sha256', json_encode([$intent, array_column($lines, 'unit_minor'), $rounding]
            + ($member ? [3 => (string) $member['member_no']] : []), JSON_THROW_ON_ERROR));
        return ['lines' => $lines, 'subtotal_minor' => $subtotal, 'discount_minor' => $intent['discount'],
            'rounding_minor' => $rounding, 'total_minor' => $net + $rounding, 'digest' => $digest]
            + ($memberInfo ? ['member' => $memberInfo] : []);
    }

    public static function commit(RequestJournal $journal, ReceiptRegister $receipts, RegisterSessions $sessions,
        int $merchant, string $register, string $key, int $actor, string $cashier, int $session, array $intent,
        string $digest, int $tender, int $duePayment = 0, int $credit = 0): array
    {
        if ($session < 1 || $tender < 0 || $tender > 1000000000 || !preg_match('/^[0-9a-f]{64}$/D', $digest)) {
            throw new DomainException('Invalid counter sale details.', 422);
        }
        if ($duePayment < 0 || $duePayment > 1000000000 || $credit < 0 || $credit > 1000000000
            || (($duePayment || $credit) && ($intent['tier'] !== 'member' || $intent['method'] !== 'cash'))) {
            throw new DomainException('Khata needs a member cash sale and valid amounts.', 422);
        }
        $journal->requirePinnedConnection();
        $orderId = 0;
        $notifications = [];
        $request = compact('register', 'actor', 'session', 'intent', 'digest', 'tender');
        if ($duePayment || $credit) {
            $request += compact('duePayment', 'credit');
        }
        try {
            $result = $journal->execute($merchant, 'pos', $key, $request,
                static function () use ($receipts, $sessions, $merchant, $register, $actor, $cashier, $session, $intent, $digest, $tender, $key, $duePayment, $credit, &$orderId, &$notifications): array {
                    $sessions->assertActive($merchant, $register, $session);
                    $quote = self::quote($merchant, $intent, true, $memberUserId);
                    if (!hash_equals($quote['digest'], $digest)) {
                        throw new DomainException('Counter price changed. Review the cart.', 409);
                    }
                    $cash = $intent['method'] === 'cash';
                    $payable = $quote['total_minor'] - $credit + $duePayment;
                    if ($credit > $quote['total_minor'] || $tender < $payable || (!$cash && $tender !== $payable)) {
                        throw new DomainException('Tender does not match the counter amount due.', 422);
                    }
                    $khata = [];
                    if ($duePayment || $credit) {
                        if (!$memberUserId) {
                            throw new DomainException('Link the member before using khata.', 409);
                        }
                        $store = AuthorizedRegister::khataStore($merchant);
                        $prefix = 'pos:' . substr(hash('sha256', $merchant . ':' . $key), 0, 48);
                        $previous = $balance = null;
                        foreach (['payment' => $duePayment, 'charge' => $credit] as $kind => $amount) {
                            if (!$amount) {
                                continue;
                            }
                            $row = Members::recordInTransaction($memberUserId, 0, $kind, $amount,
                                $kind === 'payment' ? 'cash' : '', 'Counter sale', $actor, 'up', $store, $prefix . ':' . $kind);
                            $previous ??= $row['balance_minor'] - $row['amount_minor'];
                            $balance = $row['balance_minor'];
                            if (empty($row['replayed'])) {
                                $notifications[] = [$memberUserId, $row];
                            }
                        }
                        $khata = ['previous_due_minor' => max(0, $previous), 'due_payment_minor' => $duePayment,
                            'credit_minor' => $credit, 'due_minor' => max(0, $balance), 'advance_minor' => max(0, -$balance)];
                    }
                    $order = wc_create_order(['created_via' => 'sfm4u-pos-fixture']);
                    if (is_wp_error($order)) {
                        throw new RuntimeException('WooCommerce counter order could not be created.');
                    }
                    $orderId = $order->get_id();
                    $order->set_currency('BDT');
                    if ($khata) {
                        $order->update_meta_data('_sfm4u_pos_khata', $khata);
                    }
                    $order->set_payment_method('sfm4u_counter_' . $intent['method']);
                    $order->set_payment_method_title(self::methodLabel($intent['method']));
                    $order->update_meta_data('_sfm4u_pos_register', $register); // ERPNext branch mapping (0.9.5)
                    if (!empty($quote['member'])) {
                        $order->update_meta_data('_sfmwh_member_price', 'yes');
                        $order->update_meta_data('_sfmwh_member_no_masked', $quote['member']['member_no']);
                        $order->update_meta_data('_sfmwh_member_tier', $quote['member']['tier']);
                        $order->update_meta_data('_sfmwh_member_source', 'pos');
                        if ($memberUserId !== null) {
                            $order->update_meta_data('_sfmwh_member_user', $memberUserId);
                        }
                    }
                    foreach ($quote['lines'] as $line) {
                        $item = new \WC_Order_Item_Product();
                        $item->set_product(wc_get_product($line['product']));
                        $item->set_quantity($line['quantity']);
                        $item->set_subtotal(Rule::decimal($line['subtotal_minor'], 2));
                        $item->set_total(Rule::decimal($line['total_minor'], 2));
                        $order->add_item($item);
                    }
                    if ($quote['rounding_minor'] !== 0) {
                        $fee = new \WC_Order_Item_Fee();
                        $fee->set_name(__('Cash rounding', 'sfm4u-wholesale'));
                        $fee->add_meta_data('_sfm4u_cash_rounding', 1, true);
                        $fee->set_tax_status('none');
                        $fee->set_total(wc_format_decimal($quote['rounding_minor'] / 100, 2));
                        $order->add_item($fee);
                    }
                    $order->calculate_totals(false);
                    if (wc_format_decimal($order->get_total(), 2) !== Rule::decimal($quote['total_minor'], 2)) {
                        throw new RuntimeException('Counter totals differ from the WooCommerce order.');
                    }
                    $before = [];
                    foreach ($quote['lines'] as $line) {
                        $quantity = wc_get_product($line['product'])->get_stock_quantity();
                        if ($quantity === null || (float) $quantity !== (float) (int) $quantity || $quantity < $line['quantity']) {
                            throw new DomainException('Counter stock is unavailable.', 409);
                        }
                        $before[$line['product']] = (int) $quantity;
                    }
                    wc_reserve_stock_for_order($order);
                    ManagedOrderStock::reduce($order, static fn() => $order->update_status($cash && !$credit ? 'completed' : 'on-hold'));
                    wc_release_stock_for_order($order);
                    $movements = [];
                    foreach ($quote['lines'] as $line) {
                        $id = $line['product'];
                        if ((int) wc_get_product($id)->get_stock_quantity() !== $before[$id] - $line['quantity']) {
                            throw new RuntimeException('Counter order stock deduction could not be confirmed.');
                        }
                        $movements[] = ['product_id' => $id, 'delta' => -$line['quantity'], 'type' => 'sale', 'order_id' => $order->get_id()];
                    }
                    do_action('sfm4u_inventory_capture_sale', $order);
                    $sale = array_diff_key($quote, ['digest' => 1]) + [
                        'merchant_profile' => InvoiceProfilePage::profile($merchant), 'currency' => 'BDT', 'issued_at' => gmdate('c'),
                        'cashier' => $cashier, 'actor_id' => $actor, 'session_id' => $session, 'vat_minor' => 0,
                        'tender_minor' => $tender, 'change_minor' => $tender - $payable,
                        'cash_received_minor' => $cash ? $payable : 0,
                        'tender_type' => $cash ? 'cash' : ($intent['method'] === 'card' ? 'manual_card' : 'manual_mfs'),
                        'method_label' => $intent['method'],
                    ] + $khata;
                    $receipt = $receipts->issue($merchant, $register, $order->get_id(), $sale);
                    do_action('sfm4u_pos_synthetic_before_commit', $receipt);
                    return ['response' => $receipt, 'movements' => $movements];
                });
        } catch (\Throwable $error) {
            if ($orderId > 0) {
                foreach (array_keys($intent['items']) as $product) {
                    ReceiptRegister::clearFailedCreditCaches($orderId, 0, $product);
                    wc_delete_product_transients($product);
                }
            }
            throw $error;
        }
        foreach ($notifications as [$memberId, $row]) {
            try {
                Members::notifyRecorded($memberId, $row);
            } catch (\Throwable) {
                wc_get_logger()->warning('POS khata listener failed after commit.', ['source' => 'sfm4u-pos']);
            }
        }
        return $result;
    }
}
