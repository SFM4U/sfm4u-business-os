<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

final class HoldReviewPage
{
    public function __construct(private WooQuotes $quotes) {}

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('woocommerce', __('B2B Hold Review', 'sfm4u-wholesale'),
                __('B2B Hold Review', 'sfm4u-wholesale'), 'sfm4u_b2b_settle', 'sfm4u-b2b-holds', [$this, 'render']);
        });
    }

    public function render(): void
    {
        $after = isset($_GET['after']) && is_string($_GET['after']) ? absint($_GET['after']) : 0;
        $rows = $this->quotes->holdReviews($after);
        echo '<div class="wrap"><h1>' . esc_html__('B2B Hold Review', 'sfm4u-wholesale') . '</h1>';
        if (!$rows) {
            echo '<p>' . esc_html__('No orders to review on this page.', 'sfm4u-wholesale') . '</p>';
        }
        echo '<ul>';
        foreach (array_slice($rows, 0, 50) as $row) {
            $id = (int) $row['order_id'];
            $reason = match ($row['expiry_review']) {
                'payment_allocated' => __('Payment recorded', 'sfm4u-wholesale'),
                'credit_recorded' => __('Credit recorded', 'sfm4u-wholesale'),
                default => __('Check order status', 'sfm4u-wholesale'),
            };
            $order = current_user_can('edit_shop_order', $id) ? wc_get_order($id) : false;
            $label = '#' . $id;
            echo '<li>';
            echo $order ? '<a href="' . esc_url($order->get_edit_order_url()) . '">' . esc_html($label) . '</a>' : esc_html($label);
            $currency = $row['currency'];
            $paid = (int) $row['paid_minor'];
            $open = (int) $row['amount_minor'] - $paid - (int) $row['credit_minor'];
            $balance = sprintf(
                /* translators: 1: currency and allocated payment, 2: currency and open amount. */
                __('Allocated payment %1$s, open balance %2$s', 'sfm4u-wholesale'),
                $currency . ' ' . Money::of($paid, $currency)->format(),
                $currency . ' ' . Money::of($open, $currency)->format()
            );
            echo ' · ' . esc_html($reason) . '<br>' . esc_html($balance) . '</li>';
        }
        echo '</ul>';
        if (count($rows) > 50) {
            $url = add_query_arg(['page' => 'sfm4u-b2b-holds', 'after' => (int) $rows[49]['order_id']], admin_url('admin.php'));
            echo '<a href="' . esc_url($url) . '">' . esc_html__('Next page', 'sfm4u-wholesale') . '</a>';
        }
        echo '</div>';
    }
}
