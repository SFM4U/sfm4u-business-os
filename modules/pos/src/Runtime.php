<?php
declare(strict_types=1);

namespace SFM4U\POS;

use DomainException;
use SFM4U\Inventory\{LossAwareWpdb, RequestJournal};

require_once __DIR__ . '/InvoiceProfilePage.php';
require_once __DIR__ . '/InvoiceTemplate.php';
require_once __DIR__ . '/RegisterSettingsPage.php';
require_once __DIR__ . '/SyntheticSale.php';
require_once __DIR__ . '/AuthorizedRegister.php';
require_once __DIR__ . '/RegisterAssignments.php';
require_once __DIR__ . '/RegisterPage.php';
require_once __DIR__ . '/CounterSale.php';
require_once __DIR__ . '/CounterPage.php';
require_once dirname(__DIR__, 2) . '/inventory-bridge/src/NativeOrderInventory.php';

final class Runtime
{
    public static function enabled(): bool
    {
        return defined('SFM4U_OPERATIONS_ENABLED') && SFM4U_OPERATIONS_ENABLED === true
            && (!class_exists(\SFM4U\Wholesale\Modules::class) || \SFM4U\Wholesale\Modules::on('pos'));
    }

    public static function allowedContext(): bool
    {
        return self::enabled() || (defined('WP_CLI') && WP_CLI
            && in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true));
    }

    public static function register(\wpdb $db): void
    {
        if (!self::enabled() || !$db instanceof LossAwareWpdb) {
            return;
        }
        if (get_option('sfm4u_operations_schema') !== '3') {
            add_action('admin_init', static fn() => self::install($db));
            add_action('admin_notices', [self::class, 'setupNotice']);
            return;
        }
        (new \SFM4U\Inventory\NativeOrderInventory($db))->register();
        $service = new AuthorizedRegister(new RequestJournal($db), new ReceiptRegister($db),
            new SyntheticSale(new RequestJournal($db), new ReceiptRegister($db)), new RegisterSessions($db));
        (new RegisterAssignments())->register();
        (new InvoiceProfilePage())->register();
        (new RegisterSettingsPage())->register();
        (new RegisterPage($service))->register();
        CounterPage::register();
        $register = static function () use ($service): void {
            register_rest_route('sfm4u-pos/v1', '/register', [
                'methods' => 'POST',
                'permission_callback' => static function (\WP_REST_Request $request): bool|\WP_Error {
                    if (!get_current_user_id() || !current_user_can('sfm4u_pos_cashier')) {
                        return new \WP_Error('forbidden', __('Cashier permission required.', 'sfm4u-wholesale'), ['status' => 403]);
                    }
                    return wp_verify_nonce((string) $request->get_header('X-WP-Nonce'), 'wp_rest')
                        ? true : new \WP_Error('nonce_invalid', __('Refresh the page and try again.', 'sfm4u-wholesale'), ['status' => 403]);
                },
                'callback' => static function (\WP_REST_Request $request) use ($service): array|\WP_Error {
                    $data = $request->get_json_params();
                    if (!is_array($data) || !is_string($data['action'] ?? null)
                        || !is_int($data['merchant'] ?? null) || !is_string($data['register'] ?? null)) {
                        return new \WP_Error('request_invalid', __('Check the request fields.', 'sfm4u-wholesale'), ['status' => 422]);
                    }
                    try {
                        $merchant = $data['merchant'];
                        $register = $data['register'];
                        $key = (string) $request->get_header('Idempotency-Key');
                        $number = static function (string $field) use ($data): int {
                            if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
                                throw new DomainException('Invalid numeric input.', 422);
                            }
                            return $data[$field];
                        };
                        $text = static function (string $field) use ($data): string {
                            if (!is_string($data[$field] ?? '')) {
                                throw new DomainException('Invalid text input.', 422);
                            }
                            return $data[$field] ?? '';
                        };
                        return match ($data['action']) {
                            'drawer' => $service->drawer($merchant, $register, $text('drawer_action'), $key,
                                $number('session'), $number('amount'), $text('reason'), $text('approval'), $text('expense_memo')),
                            'counter_quote', 'counter_sale' => $service->counter($merchant, $register, $key, $data, $data['action'] === 'counter_sale'),
                            'member_due', 'member_due_history' => $service->memberDue($merchant, $register,
                                $text('member'), $data['action'] === 'member_due_history'),
                            'member_due_payment' => $service->payMemberDue($merchant, $register, $key,
                                $number('session'), $text('member'), $number('amount')),
                            'search' => $service->search($merchant,$register,$text('search')),
                            'park' => $service->park($merchant,$register,$key,$number('product'),$number('quantity')),
                            'resume' => $service->resume($merchant,$register,$text('basket')),
                            'sale' => $service->sale($merchant, $register, $key, $number('product'),
                                $number('quantity'), $number('tender'), $number('session'),$text('tender_type')?:'cash',$text('tender_reference')),
                            'credit' => $service->credit($merchant, $register, $key, $number('order'),
                                $number('quantity'), is_bool($data['resellable'] ?? null) ? $data['resellable'] : throw new DomainException('Invalid stock disposition.', 422), $number('session')),
                            'cart_credit' => $service->cartCredit($merchant, $register, $key, $number('order'), $number('session'),
                                is_array($data['items'] ?? null) ? $data['items'] : throw new DomainException('Invalid credit lines.', 422),
                                is_bool($data['resellable'] ?? null) ? $data['resellable'] : throw new DomainException('Invalid stock disposition.', 422)),
                            'reprint' => $service->reprint($merchant, $register, $number('order')),
                            'invoice' => $service->invoice($merchant, $register, $number('order'),$text('paper')?:'a4'),
                            default => throw new DomainException('Invalid register action.', 422),
                        };
                    } catch (DrawerAlreadyOpen $error) {
                        /* translators: %d: open drawer session number. */
                        $message = __('Drawer is already open (session %d). Close it first or continue selling.', 'sfm4u-wholesale');
                        if (determine_locale() === 'bn_BD' && $message === 'Drawer is already open (session %d). Close it first or continue selling.') {
                            $message = 'ড্রয়ার আগে থেকেই খোলা আছে (সেশন %d)। আগে ড্রয়ার বন্ধ করুন অথবা বিক্রি চালিয়ে যান।';
                        }
                        return new \WP_Error('drawer_already_open', sprintf($message, $error->sessionId), ['status' => 409]);
                    } catch (DomainException $error) {
                        $status = in_array($error->getCode(), [403, 404, 409, 422], true) ? $error->getCode() : 422;
                        // Messages a cashier can act on (card problems, prices) are shown as they are; the rest stay generic.
                        $clear = ['Card not found.', 'This card does not get the member price. Only an active Business card or a paid Business Black card does.',
                            'Membership changed. Scan the card again and review the cart.', 'Counter stock is unavailable.',
                            'Wholesale pricing is unavailable.', 'Wholesale pricing is unavailable for a cart line.'];
                        if (in_array($error->getMessage(), $clear, true) || str_contains($error->getMessage(), 'below the lowest price the shop accepts')) {
                            return new \WP_Error('counter_rejected', __($error->getMessage(), 'sfm4u-wholesale'), ['status' => $status]);
                        }
                        return new \WP_Error('register_rejected', __('Check the request and your register assignment. Retry with the same key.', 'sfm4u-wholesale'), ['status' => $status]);
                    } catch (\Throwable) {
                        return new \WP_Error('database_unavailable',
                            __('The operation could not be confirmed. Retry with the same key.', 'sfm4u-wholesale'), ['status' => 503]);
                    }
                },
            ]);
        };
        if (did_action('rest_api_init')) {
            $register();
        } else {
            add_action('rest_api_init', $register);
        }
    }

    public static function install(\wpdb $db): void
    {
        if (!self::enabled() || !$db instanceof LossAwareWpdb || !current_user_can('activate_plugins')) {
            return;
        }
        try {
            (new \SFM4U\Inventory\PurchaseOrders($db))->install();
            (new \SFM4U\Inventory\StockCounts($db))->install();
            (new RegisterSessions($db))->install();
            (new \SFM4U\Inventory\NativeOrderInventory($db))->install();
            update_option('sfm4u_operations_schema', '3');
        } catch (\Throwable) {
            // Never advertise a partial schema or prevent an administrator repairing it.
            delete_option('sfm4u_operations_schema');
        }
    }

    public static function setupNotice(): void
    {
        if (!current_user_can('manage_woocommerce') || get_option('sfm4u_operations_schema') === '3') return;
        echo '<div class="notice notice-error"><p>' . esc_html__(
            'Operations setup is incomplete. Ask an administrator to verify transactional tables and retry activation.',
            'sfm4u-wholesale') . '</p></div>';
    }
}
