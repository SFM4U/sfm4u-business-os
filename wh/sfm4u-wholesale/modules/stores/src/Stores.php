<?php
declare(strict_types=1);

namespace SFM4U\Modules\Stores;

use SFM4U\Wholesale\{ModuleContract, ModuleHome, Modules};
use WP_Post;

final class Stores implements ModuleContract
{
    public const TYPE = 'sfm_store';
    public const PAGE = 'sfm-stores';
    private const PROFILE = 'sfm_stores_profile';
    private const VERIFIED = 'sfm_stores_verified';

    public static function register(): void
    {
        add_filter('sfm4u_bos_modules', static fn(array $modules): array => $modules + [
            'stores' => ['Stores', 'দোকান', 'Store profiles, approval and verification.', self::PAGE, self::class],
        ]);
        if (!Modules::on('stores')) {
            return;
        }
        add_action('init', [self::class, 'postType']);
        add_action('admin_menu', [self::class, 'menu']);
        add_filter('woocommerce_prevent_admin_access', [self::class, 'allowStorePage']);
        add_action('admin_post_sfm_stores_save', [self::class, 'save']);
        add_shortcode('sfm4u_stores', [self::class, 'directory']);
    }

    public static function postType(): void
    {
        // All writes must go through the module's approval and owner checks.
        $writes = array_fill_keys(['create_posts', 'edit_post', 'edit_posts', 'edit_others_posts',
            'edit_private_posts', 'edit_published_posts', 'publish_posts', 'delete_post', 'delete_posts',
            'delete_private_posts', 'delete_published_posts', 'delete_others_posts'], 'do_not_allow');
        register_post_type(self::TYPE, ['public' => false, 'show_ui' => false, 'show_in_rest' => false,
            'map_meta_cap' => false, 'capabilities' => $writes, 'supports' => ['title', 'author']]);
    }

    public static function dashboard(): array
    {
        $counts = self::manager() ? wp_count_posts(self::TYPE) : null;
        return [
            'numbers' => $counts ? [
                [self::label('Approved stores', 'অনুমোদিত দোকান'), (string) ($counts->publish ?? 0)],
                [self::label('Pending approval', 'অনুমোদনের অপেক্ষায়'), (string) ($counts->pending ?? 0)],
            ] : [],
            'pages' => [[self::label('Manage stores', 'দোকান পরিচালনা'), self::PAGE]],
            'settings' => [['directory_enabled', self::label('Public store directory', 'সবার জন্য দোকানের তালিকা'), 'checkbox', false]],
            'help' => [
                'Create a pending store, assign its owner, then approve or verify it. Owners with a Stores grant manage their own profiles. Use [sfm4u_stores] for the public directory.',
                'দোকান তৈরি করে মালিক ঠিক করুন, তারপর অনুমোদন বা যাচাই করুন। অনুমতি পাওয়া মালিক নিজের দোকান সম্পাদনা করবেন। দোকানের তালিকার জন্য [sfm4u_stores] ব্যবহার করুন।',
            ],
        ];
    }

    public static function menu(): void
    {
        $title = self::label('Stores', 'দোকান');
        if (self::manager()) {
            add_submenu_page(Modules::SLUG, $title, $title, 'read', self::PAGE, [self::class, 'page']);
        } elseif (self::canOpen()) {
            add_menu_page($title, $title, 'read', self::PAGE, [self::class, 'page'], 'dashicons-store');
        }
    }

    public static function allowStorePage(bool $prevent): bool
    {
        if (($GLOBALS['pagenow'] ?? '') === 'admin.php' && ($_GET['page'] ?? '') === self::PAGE
            && Modules::on('stores') && self::canOpen()) {
            return false;
        }
        return $prevent;
    }

    private static function manager(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    private static function canOpen(): bool
    {
        return self::manager() || current_user_can('sfm_bos_use_stores');
    }

    private static function label(string $en, string $bn): string
    {
        return str_starts_with(determine_locale(), 'bn') ? $bn : $en;
    }

    private static function store(int $id): ?WP_Post
    {
        $post = $id > 0 ? get_post($id) : null;
        return $post instanceof WP_Post && $post->post_type === self::TYPE
            && in_array($post->post_status, ['pending', 'publish'], true) ? $post : null;
    }

    public static function mayEdit(int $id): bool
    {
        $store = self::store($id);
        return $store && Profile::mayEdit(get_current_user_id(), (int) $store->post_author,
            self::manager(), current_user_can('sfm_bos_use_stores'));
    }

    private static function fields(int $id): array
    {
        $saved = get_post_meta($id, self::PROFILE, true);
        return Profile::publicFields(is_array($saved) ? $saved : []);
    }

    /** Returns only intentionally public fields for an approved shop. */
    public static function publicProfile(int $id): ?array
    {
        $store = self::store($id);
        if (!Modules::on('stores') || !$store || $store->post_status !== 'publish') {
            return null;
        }
        return ['id' => $id, 'name' => $store->post_title,
            'verified' => get_post_meta($id, self::VERIFIED, true) === '1'] + self::fields($id);
    }

    public static function page(): void
    {
        if (!self::canOpen()) {
            wp_die(esc_html(self::label('Access denied.', 'অনুমতি নেই।')), '', ['response' => 403]);
        }
        $id = absint($_GET['store'] ?? 0);
        if ($id && !self::mayEdit($id)) {
            wp_die(esc_html(self::label('Store unavailable.', 'দোকান পাওয়া যায়নি।')), '', ['response' => 403]);
        }
        $query = ['post_type' => self::TYPE, 'post_status' => ['pending', 'publish'], 'posts_per_page' => 25,
            'paged' => max(1, absint($_GET['store_page'] ?? 1))];
        if (!self::manager()) {
            $query['author'] = get_current_user_id();
        }
        $list = new \WP_Query($query);
        echo '<div class="wrap"><h1>' . esc_html(self::label('Stores', 'দোকান')) . '</h1><ul>';
        foreach ($list->posts as $store) {
            $url = add_query_arg(['page' => self::PAGE, 'store' => $store->ID], admin_url('admin.php'));
            echo '<li><a href="' . esc_url($url) . '">' . esc_html($store->post_title) . '</a> · '
                . esc_html($store->post_status === 'publish' ? self::label('Approved', 'অনুমোদিত') : self::label('Pending', 'অপেক্ষায়')) . '</li>';
        }
        echo '</ul>';
        $page = $query['paged'];
        if ($page > 1 || $page < $list->max_num_pages) {
            echo '<nav aria-label="' . esc_attr(self::label('Store pages', 'দোকানের পাতা')) . '">';
            foreach ([$page - 1 => self::label('Previous', 'আগের'), $page + 1 => self::label('Next', 'পরের')] as $number => $label) {
                if ($number >= 1 && $number <= $list->max_num_pages) {
                    echo '<a href="' . esc_url(add_query_arg(['page' => self::PAGE, 'store_page' => $number], admin_url('admin.php')))
                        . '">' . esc_html($label) . '</a> ';
                }
            }
            echo '</nav>';
        }
        if ($id || self::manager()) {
            self::form($id);
        }
        echo '</div>';
    }

    private static function form(int $id): void
    {
        $store = self::store($id);
        $fields = self::fields($id);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sfm_stores_save_' . $id);
        echo '<input type="hidden" name="action" value="sfm_stores_save"><input type="hidden" name="store" value="' . esc_attr((string) $id) . '">';
        if (self::manager()) {
            self::input('name', self::label('Store name', 'দোকানের নাম'), $store->post_title ?? '');
        } else {
            echo '<h2>' . esc_html($store->post_title) . '</h2>';
        }
        $labels = [
            'owner_name' => ['Public owner name', 'মালিকের প্রকাশ্য নাম'],
            'owner_bio' => ['Public owner bio', 'মালিকের প্রকাশ্য পরিচয়'],
            'address' => ['Public store address', 'দোকানের প্রকাশ্য ঠিকানা'],
            'hours' => ['Opening hours', 'খোলার সময়'],
            'logo_id' => ['Logo image attachment ID', 'লোগো ছবির আইডি'],
            'banner_id' => ['Banner image attachment ID', 'ব্যানার ছবির আইডি'],
        ];
        foreach ($labels as $key => [$en, $bn]) {
            self::input($key, self::label($en, $bn), (string) ($fields[$key] ?? ''));
        }
        self::checkbox('pickup', self::label('Offer pickup', 'দোকান থেকে নেওয়ার সুবিধা'), !empty($fields['pickup']));
        if (self::manager()) {
            self::input('owner_id', self::label('Owner user ID', 'মালিকের ইউজার আইডি'), (string) ($store->post_author ?? ''));
            self::checkbox('approved', self::label('Approved', 'অনুমোদিত'), ($store->post_status ?? '') === 'publish');
            self::checkbox('verified', self::label('Verified', 'যাচাইকৃত'), get_post_meta($id, self::VERIFIED, true) === '1');
        }
        submit_button(self::label('Save store', 'দোকান সংরক্ষণ'));
        echo '</form>';
    }

    private static function input(string $name, string $label, string $value): void
    {
        echo '<p><label>' . esc_html($label) . ' <input class="regular-text" name="' . esc_attr($name)
            . '" value="' . esc_attr($value) . '"></label></p>';
    }

    private static function checkbox(string $name, string $label, bool $checked): void
    {
        echo '<p><label><input type="checkbox" name="' . esc_attr($name) . '" value="1" '
            . checked($checked, true, false) . '> ' . esc_html($label) . '</label></p>';
    }

    public static function save(): void
    {
        $id = absint($_POST['store'] ?? 0);
        check_admin_referer('sfm_stores_save_' . $id);
        $result = self::update($id, wp_unslash($_POST));
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['response' => $result->get_error_data()]);
        }
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'store' => $result], admin_url('admin.php')));
        exit;
    }

    public static function update(int $id, array $input): int|\WP_Error
    {
        $manager = self::manager();
        if (!Modules::on('stores') || ($id ? !self::mayEdit($id) : !$manager)) {
            return new \WP_Error('sfm_store_forbidden', 'Store update is not allowed.', 403);
        }
        $store = self::store($id);
        $owner = $manager ? absint($input['owner_id'] ?? 0) : (int) $store->post_author;
        $name = $manager ? (is_string($input['name'] ?? null) ? sanitize_text_field($input['name']) : '') : $store->post_title;
        if ($name === '' || strlen($name) > 512 || !$owner || !get_userdata($owner)) {
            return new \WP_Error('sfm_store_invalid', 'Enter a store name and an existing owner.', 422);
        }
        $fields = [];
        foreach (['owner_name', 'owner_bio', 'address', 'hours'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || strlen($value) > 2000) {
                return new \WP_Error('sfm_store_invalid', 'Check the public profile fields.', 422);
            }
            $fields[$key] = sanitize_text_field($value);
        }
        $previous = self::fields($id);
        foreach (['logo_id', 'banner_id'] as $key) {
            $attachment = absint($input[$key] ?? 0);
            if ($attachment && (!wp_attachment_is_image($attachment)
                || (!$manager && $attachment !== (int) ($previous[$key] ?? 0)
                    && (int) get_post_field('post_author', $attachment) !== $owner))) {
                return new \WP_Error('sfm_store_image', 'Choose an image you own.', 422);
            }
            $fields[$key] = $attachment;
        }
        $fields['pickup'] = !empty($input['pickup']);
        if (!$manager) {
            update_post_meta($id, self::PROFILE, wp_slash($fields));
            return $id;
        }
        $status = empty($input['approved']) ? 'pending' : 'publish';
        $result = wp_insert_post(wp_slash(['ID' => $id, 'post_type' => self::TYPE, 'post_status' => $status,
            'post_title' => $name, 'post_author' => $owner]), true);
        if (is_wp_error($result)) {
            return new \WP_Error('sfm_store_write', 'Store could not be saved.', 500);
        }
        update_post_meta($result, self::PROFILE, wp_slash($fields));
        if ($manager) {
            update_post_meta($result, self::VERIFIED, Profile::mayVerify($manager, $status) && !empty($input['verified']) ? '1' : '0');
        }
        return $result;
    }

    public static function directory(): string
    {
        if (!Modules::on('stores') || !ModuleHome::setting('stores', 'directory_enabled')) {
            return '';
        }
        $id = absint($_GET['sfm_store'] ?? 0);
        if ($id) {
            $profile = self::publicProfile($id);
            if (!$profile) {
                return '';
            }
            $html = '<article><h2>' . esc_html($profile['name']) . '</h2>';
            if ($profile['verified']) {
                $html .= '<p>' . esc_html(self::label('Verified store', 'যাচাইকৃত দোকান')) . '</p>';
            }
            foreach (['banner_id', 'logo_id'] as $key) {
                if (!empty($profile[$key])) {
                    $html .= wp_get_attachment_image((int) $profile[$key], 'medium');
                }
            }
            foreach (['owner_name', 'owner_bio', 'address', 'hours'] as $key) {
                if (!empty($profile[$key])) {
                    $html .= '<p>' . esc_html($profile[$key]) . '</p>';
                }
            }
            return $html . '</article>';
        }
        $page = max(1, absint($_GET['sfm_stores_page'] ?? 1));
        $query = new \WP_Query(['post_type' => self::TYPE, 'post_status' => 'publish',
            'posts_per_page' => 25, 'paged' => $page]);
        $html = '<ul>';
        foreach ($query->posts as $store) {
            $html .= '<li><a href="' . esc_url(add_query_arg('sfm_store', $store->ID)) . '">'
                . esc_html($store->post_title) . '</a></li>';
        }
        $html .= '</ul>';
        if ($page > 1) {
            $html .= '<a href="' . esc_url(add_query_arg('sfm_stores_page', $page - 1)) . '">' . esc_html(self::label('Previous', 'আগের')) . '</a> ';
        }
        if ($page < $query->max_num_pages) {
            $html .= '<a href="' . esc_url(add_query_arg('sfm_stores_page', $page + 1)) . '">' . esc_html(self::label('Next', 'পরের')) . '</a>';
        }
        return $html;
    }
}
