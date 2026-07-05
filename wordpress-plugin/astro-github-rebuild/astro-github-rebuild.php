<?php
/**
 * Plugin Name: Astro GitHub Rebuild Webhook
 * Description: Запускает пересборку Astro-сайта в GitHub Actions при изменении в WordPress.
 * Version: 1.1.0
 * Author: KirillGLE
 * License: GPL-2.0+
 * Text Domain: astro-github-rebuild
 */

if (!defined('ABSPATH')) {
    exit;
}

class Astro_GitHub_Rebuild_Webhook {

    private $option_name = 'astro_rebuild_settings';
    private $options;

    public function __construct() {
        $this->options = get_option($this->option_name, []);

        add_action('admin_init', [$this, 'handle_manual_trigger']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'show_notices']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);

        $this->register_hooks();
    }

    /* ========================= ADMIN ========================= */

    public function add_settings_page() {
        add_options_page(
            'Astro Rebuild',
            'Astro Rebuild',
            'manage_options',
            'astro-github-rebuild',
            [$this, 'render_settings_page']
        );
    }

    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=astro-github-rebuild') . '">Настройки</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function register_settings() {
        register_setting('astro_rebuild_group', $this->option_name, [$this, 'sanitize_settings']);

        add_settings_section('astro_rebuild_main', 'Подключение к GitHub', null, 'astro-github-rebuild');

        add_settings_field('github_pat', 'GitHub Personal Access Token', [$this, 'field_pat'], 'astro-github-rebuild', 'astro_rebuild_main');
        add_settings_field('github_owner', 'Владелец репозитория', [$this, 'field_owner'], 'astro-github-rebuild', 'astro_rebuild_main');
        add_settings_field('github_repo', 'Имя репозитория', [$this, 'field_repo'], 'astro-github-rebuild', 'astro_rebuild_main');
        add_settings_field('lock_timeout', 'Таймаут между вызовами (сек)', [$this, 'field_timeout'], 'astro-github-rebuild', 'astro_rebuild_main');
        add_settings_field('enabled_hooks', 'Отслеживать изменения', [$this, 'field_hooks'], 'astro-github-rebuild', 'astro_rebuild_main');
    }

    public function sanitize_settings($input) {
        $clean = [];
        $clean['github_pat']   = sanitize_text_field($input['github_pat'] ?? '');
        $clean['github_owner'] = sanitize_text_field($input['github_owner'] ?? '');
        $clean['github_repo']  = sanitize_text_field($input['github_repo'] ?? '');
        $clean['lock_timeout'] = absint($input['lock_timeout'] ?? 60);
        if ($clean['lock_timeout'] < 10) {
            $clean['lock_timeout'] = 10;
        }
        $clean['enabled_hooks'] = (isset($input['enabled_hooks']) && is_array($input['enabled_hooks']))
            ? array_map('sanitize_text_field', $input['enabled_hooks'])
            : ['posts', 'taxonomies', 'menus', 'media', 'options', 'plugins_themes'];
        return $clean;
    }

    public function field_pat() {
        $val = esc_attr($this->options['github_pat'] ?? '');
        echo '<input type="password" name="' . esc_attr($this->option_name) . '[github_pat]" value="' . $val . '" class="regular-text">';
        echo '<p class="description">Токен с правами <code>repo</code> (приватный реп) или <code>public_repo</code>.</p>';
    }

    public function field_owner() {
        $val = esc_attr($this->options['github_owner'] ?? '');
        echo '<input type="text" name="' . esc_attr($this->option_name) . '[github_owner]" value="' . $val . '" class="regular-text" placeholder="myusername">';
    }

    public function field_repo() {
        $val = esc_attr($this->options['github_repo'] ?? '');
        echo '<input type="text" name="' . esc_attr($this->option_name) . '[github_repo]" value="' . $val . '" class="regular-text" placeholder="ossified-osiris">';
    }

    public function field_timeout() {
        $val = absint($this->options['lock_timeout'] ?? 60);
        echo '<input type="number" name="' . esc_attr($this->option_name) . '[lock_timeout]" value="' . $val . '" class="small-text">';
        echo '<p class="description">Минимальный интервал между webhook. Защита от спама при массовых операциях.</p>';
    }

    public function field_hooks() {
        $enabled = $this->options['enabled_hooks'] ?? ['posts', 'taxonomies', 'menus', 'media', 'options', 'plugins_themes'];
        $hooks = [
            'posts'          => 'Записи, страницы, произвольные типы (CPT)',
            'taxonomies'     => 'Рубрики, метки, таксономии',
            'menus'          => 'Меню навигации',
            'media'          => 'Медиафайлы',
            'users'          => 'Пользователи',
            'options'        => 'Настройки сайта, Customizer, ACF Options',
            'plugins_themes' => 'Обновления ядра, переключение тем',
        ];
        foreach ($hooks as $key => $label) {
            $checked = in_array($key, $enabled, true) ? 'checked' : '';
            echo '<label style="display:block;margin-bottom:4px;">';
            echo '<input type="checkbox" name="' . esc_attr($this->option_name) . '[enabled_hooks][]" value="' . esc_attr($key) . '" ' . $checked . '> ' . esc_html($label);
            echo '</label>';
        }
    }

    public function handle_manual_trigger() {
        if (!isset($_POST['astro_rebuild_manual'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'astro_rebuild_manual')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $success = $this->dispatch(true);
        wp_safe_redirect(add_query_arg([
            'page' => 'astro-github-rebuild',
            'rebuild_status' => $success ? 'success' : 'error'
        ], admin_url('options-general.php')));
        exit;
    }

    public function show_notices() {
        $pat   = $this->options['github_pat'] ?? '';
        $owner = $this->options['github_owner'] ?? '';
        $repo  = $this->options['github_repo'] ?? '';

        if (empty($pat) || empty($owner) || empty($repo)) {
            echo '<div class="notice notice-warning"><p><strong>Astro Rebuild:</strong> Заполни настройки GitHub в <a href="' . esc_url(admin_url('options-general.php?page=astro-github-rebuild')) . '">разделе настроек</a>.</p></div>';
        }

        if (isset($_GET['rebuild_status']) && isset($_GET['page']) && $_GET['page'] === 'astro-github-rebuild') {
            if ($_GET['rebuild_status'] === 'success') {
                echo '<div class="notice notice-success"><p>✅ Webhook отправлен. Пересборка запущена в GitHub Actions.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ Не удалось отправить webhook. Проверь токен, имя репозитория и логи сервера.</p></div>';
            }
        }
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Astro GitHub Rebuild</h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('astro_rebuild_group');
                do_settings_sections('astro-github-rebuild');
                submit_button('Сохранить настройки');
                ?>
            </form>

            <hr>

            <h2>Ручной запуск</h2>
            <form method="post">
                <?php wp_nonce_field('astro_rebuild_manual'); ?>
                <p>Нажми кнопку, чтобы немедленно запустить пересборку сайта.</p>
                <?php submit_button('🚀 Запустить пересборку сейчас', 'secondary', 'astro_rebuild_manual'); ?>
            </form>
        </div>
        <?php
    }

    /* ========================= WEBHOOK ========================= */

    /**
     * Отправляет webhook на GitHub.
     *
     * @param bool $sync Если true — ждёт ответа для проверки (ручной режим).
     *                   Если false — fire-and-forget (автоматические хуки).
     */
    private function dispatch($sync = false) {
        // Не мешаем AJAX (загрузка медиа), REST API (редактор) и cron
        if (wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }
        // if (defined('REST_REQUEST') && REST_REQUEST) {
        //     return false;
        // }

        $pat   = $this->options['github_pat'] ?? '';
        $owner = $this->options['github_owner'] ?? '';
        $repo  = $this->options['github_repo'] ?? '';

        if (empty($pat) || empty($owner) || empty($repo)) {
            return false;
        }

        $timeout  = absint($this->options['lock_timeout'] ?? 60);
        $lock_key = 'astro_rebuild_lock';

        if (get_transient($lock_key)) {
            return false;
        }
        set_transient($lock_key, true, $timeout);

        $api_url = "https://api.github.com/repos/{$owner}/{$repo}/dispatches";

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $pat,
                'Accept'        => 'application/vnd.github.v3+json',
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'WordPress/Astro-Webhook/1.1.0',
            ],
            'body'     => wp_json_encode(['event_type' => 'wp-content-updated']),
            'timeout'  => 15,
            'blocking' => $sync,
        ]);

        if (is_wp_error($response)) {
            if ($sync) {
                error_log('Astro rebuild webhook error: ' . $response->get_error_message());
            }
            delete_transient($lock_key);
            return false;
        }

        if (!$sync) {
            return true;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 204) {
            error_log('Astro rebuild webhook unexpected status: ' . $code);
            delete_transient($lock_key);
            return false;
        }

        return true;
    }

    /**
     * Обёртка для хуков, которые передают аргументы.
     * Предотвращает случайную передачу аргументов в $sync параметр dispatch().
     */
    public function on_dispatch() {
        $this->dispatch(false);
    }

    /* ========================= HOOKS ========================= */

    private function register_hooks() {
        $enabled = $this->options['enabled_hooks'] ?? ['posts', 'taxonomies', 'menus', 'media', 'options', 'plugins_themes'];

        if (in_array('posts', $enabled, true)) {
            add_action('save_post', [$this, 'on_save_post']);
            add_action('transition_post_status', [$this, 'on_transition_post_status'], 10, 3);
            add_action('deleted_post', [$this, 'on_dispatch']);
        }

        if (in_array('taxonomies', $enabled, true)) {
            add_action('created_term', [$this, 'on_dispatch']);
            add_action('edited_term', [$this, 'on_dispatch']);
            add_action('delete_term', [$this, 'on_dispatch']);
        }

        if (in_array('menus', $enabled, true)) {
            add_action('wp_update_nav_menu', [$this, 'on_dispatch']);
            add_action('wp_delete_nav_menu', [$this, 'on_dispatch']);
        }

        if (in_array('media', $enabled, true)) {
            add_action('add_attachment', [$this, 'on_dispatch']);
            add_action('edit_attachment', [$this, 'on_dispatch']);
            add_action('delete_attachment', [$this, 'on_dispatch']);
        }

        if (in_array('users', $enabled, true)) {
            add_action('profile_update', [$this, 'on_dispatch']);
            add_action('user_register', [$this, 'on_dispatch']);
            add_action('deleted_user', [$this, 'on_dispatch']);
        }

        if (in_array('options', $enabled, true)) {
            add_action('customize_save_after', [$this, 'on_dispatch']);
            add_action('updated_option', [$this, 'on_updated_option']);
            if (function_exists('acf')) {
                add_action('acf/save_post', [$this, 'on_acf_save_post']);
            }
        }

        if (in_array('plugins_themes', $enabled, true)) {
            add_action('upgrader_process_complete', [$this, 'on_dispatch']);
            add_action('switch_theme', [$this, 'on_dispatch']);
        }
    }

    public function on_save_post($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        $this->dispatch(false);
    }

    public function on_transition_post_status($new_status, $old_status, $post) {
        if (wp_is_post_autosave($post->ID) || wp_is_post_revision($post->ID)) {
            return;
        }
        $this->dispatch(false);
    }

    public function on_updated_option($option) {
        $tracked = [
            'blogname',
            'blogdescription',
            'site_icon',
            'posts_per_page',
            'show_on_front',
            'page_on_front',
            'page_for_posts',
        ];
        if (in_array($option, $tracked, true)) {
            $this->dispatch(false);
            return;
        }
        if (strpos($option, 'widget_') === 0) {
            $this->dispatch(false);
        }
    }

    public function on_acf_save_post($post_id) {
        if (is_string($post_id) && strpos($post_id, 'options') !== false) {
            $this->dispatch(false);
        }
    }
}

// Инициализация только после полной загрузки WordPress
add_action('plugins_loaded', function () {
    new Astro_GitHub_Rebuild_Webhook();
});
