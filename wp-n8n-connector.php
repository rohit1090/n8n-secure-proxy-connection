<?php
/**
 * Plugin Name: WP n8n Connector
 * Description: Securely triggers n8n workflows with activation toggles, execution logs, drag-and-drop, and modular extensions.
 * Version:     1.7.0
 * Author:      Custom
 */

if (!defined('ABSPATH')) exit;

class Manually_Trigger_N8N_Workflow {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Core AJAX Proxies
        add_action('wp_ajax_n8n_proxy_get_workflows', [$this, 'proxy_get_workflows']);
        add_action('wp_ajax_n8n_proxy_run_workflow', [$this, 'proxy_run_workflow']);
        add_action('wp_ajax_n8n_proxy_toggle_workflow', [$this, 'proxy_toggle_workflow']);
        add_action('wp_ajax_n8n_proxy_get_executions', [$this, 'proxy_get_executions']);
        add_action('wp_ajax_n8n_save_workflow_order', [$this, 'proxy_save_workflow_order']);

        // Load Advanced Modular Extensions File (now just the node canvas proxy)
        if (file_exists(plugin_dir_path(__FILE__) . 'includes/n8n-advanced-features.php')) {
            require_once plugin_dir_path(__FILE__) . 'includes/n8n-advanced-features.php';
        }
    }

    public static function get_settings() {
        $url = defined('N8N_API_URL') ? N8N_API_URL : get_option('n8n_api_url', '');
        $key = defined('N8N_API_KEY') ? N8N_API_KEY : get_option('n8n_api_key', '');
        $webhook = defined('N8N_MASTER_WEBHOOK') ? N8N_MASTER_WEBHOOK : get_option('n8n_master_webhook', '');

        return [
            'url'     => rtrim($url, '/'),
            'key'     => $key,
            'webhook' => esc_url_raw($webhook)
        ];
    }

  public function add_admin_menu() {
    add_menu_page(
        'WP n8n Connector',
        'WP n8n Connector',
        'manage_options',
        'wp-n8n-connector',
        [$this, 'render_dashboard'],
        $this->get_menu_icon(),
        25
    );
}

private function get_menu_icon() {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="26" viewBox="0 0 32 26"><path fill="black" fill-rule="evenodd" d="M27.2 11.396a3.2 3.2 0 0 1-3.1-2.4h-3.667a1.6 1.6 0 0 0-1.578 1.336l-.132.79a3.2 3.2 0 0 1-1.04 1.874 3.2 3.2 0 0 1 1.04 1.874l.132.789a1.6 1.6 0 0 0 1.578 1.336h.468a3.201 3.201 0 1 1-.001 1.6h-.467a3.2 3.2 0 0 1-3.156-2.673l-.132-.79a1.6 1.6 0 0 0-1.578-1.336h-1.268a3.2 3.2 0 0 1-6.198 0H6.299a3.2 3.2 0 1 1 .001-1.6h1.8a3.2 3.2 0 0 1 6.2 0h1.267a1.6 1.6 0 0 0 1.578-1.338l.132-.79a3.2 3.2 0 0 1 3.156-2.672h3.668a3.201 3.201 0 0 1 6.299.8 3.2 3.2 0 0 1-3.2 3.2m0-1.6a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2m-24 4.8a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2m9.6-1.6a1.6 1.6 0 1 1-3.2 0 1.6 1.6 0 0 1 3.2 0m12.8 4.8a1.6 1.6 0 1 1-3.2 0 1.6 1.6 0 0 1 3.2 0" clip-rule="evenodd"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

    public function register_settings() {
        register_setting('n8n_manual_settings', 'n8n_api_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('n8n_manual_settings', 'n8n_api_key', ['sanitize_callback' => [$this, 'sanitize_api_key']]);
        register_setting('n8n_manual_settings', 'n8n_master_webhook', ['sanitize_callback' => 'esc_url_raw']);
    }

    public function sanitize_api_key($value) {
        $value = sanitize_text_field($value);
        // Blank submission means "keep the existing key" — the field is never pre-filled with the real value.
        return $value === '' ? get_option('n8n_api_key', '') : $value;
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_wp-n8n-connector') return;

        wp_enqueue_style('n8n-admin-css', plugins_url('assets/css/n8n-admin.css', __FILE__), [], '1.7.0');
        wp_enqueue_script('jquery-ui-sortable');

        wp_enqueue_script('n8n-admin-js', plugins_url('assets/js/n8n-admin.js', __FILE__), ['jquery', 'jquery-ui-sortable'], '1.7.0', true);
        wp_enqueue_script('n8n-advanced-js', plugins_url('assets/js/n8n-advanced.js', __FILE__), ['jquery', 'n8n-admin-js'], '1.7.0', true);

        wp_localize_script('n8n-admin-js', 'n8nData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('n8n_manual_nonce')
        ]);
    }

    public function render_dashboard() {
        ?>
        <div class="wrap" style="max-width: 1000px; margin-top: 20px;">
            <h1 style="font-size: 23px; font-weight: 600; margin-bottom: 5px;">WP n8n Connector</h1>
            <p style="color: #646970; margin-bottom: 20px;">Manage your automations, logs, and node pipelines.</p>

            <div class="n8n-tabs">
                <a href="#workflows" class="n8n-tab active" data-target="tab-workflows">Workflows</a>
                <a href="#logs" class="n8n-tab" data-target="tab-logs">Execution Logs</a>
                <a href="#settings" class="n8n-tab" data-target="tab-settings">Settings</a>
            </div>

            <div id="tab-workflows" class="n8n-tab-content active">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="font-size: 18px; margin: 0;">Available Workflows <span id="n8n-save-status" style="font-size: 12px; font-weight: normal; color: #008a20; margin-left: 10px;"></span></h2>
                    <button id="n8n-fetch-btn" class="button button-secondary">Refresh List</button>
                </div>
                <div id="n8n-workflow-container"><p style="color: #646970;"><em>Loading workflows...</em></p></div>
            </div>

            <div id="tab-logs" class="n8n-tab-content" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="font-size: 18px; margin: 0;">Recent Executions</h2>
                    <button id="n8n-refresh-logs" class="button button-secondary">Refresh Logs</button>
                </div>
                <div id="n8n-logs-container"><p style="color: #646970;"><em>Loading execution history...</em></p></div>
            </div>

            <div id="tab-settings" class="n8n-tab-content" style="display: none;">
                <div class="n8n-card" style="display: block; max-width: 700px; padding: 25px; cursor: default;">
                    <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #f0f0f1; padding-bottom: 12px;">Connection & Credentials</h3>
                    <form method="post" action="options.php">
                        <?php settings_fields('n8n_manual_settings'); ?>
                        <p style="margin-top: 15px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">n8n Instance URL:</label>
                            <input type="url" name="n8n_api_url" value="<?php echo esc_attr(get_option('n8n_api_url')); ?>" class="regular-text" style="width: 100%; max-width: 500px;" required>
                        </p>
                        <p>
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">n8n API Key:</label>
                            <input type="password" name="n8n_api_key" value="" placeholder="<?php echo get_option('n8n_api_key') ? esc_attr('•••••••••••••• (leave blank to keep current key)') : ''; ?>" class="regular-text" style="width: 100%; max-width: 500px;" <?php echo get_option('n8n_api_key') ? '' : 'required'; ?>>
                        </p>
                        <p>
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Master Webhook Router URL:</label>
                            <input type="url" name="n8n_master_webhook" value="<?php echo esc_attr(get_option('n8n_master_webhook')); ?>" class="regular-text" style="width: 100%; max-width: 500px;" required>
                        </p>
                        <p style="margin-top: 20px;"><?php submit_button('Save Changes', 'primary', 'submit', false); ?></p>
                    </form>
                </div>
            </div>
        </div>

        <div id="n8n-node-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; justify-content:center; align-items:center;">
            <div style="background:#fff; width:92vw; max-width:1300px; height:85vh; padding:20px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15); position:relative; display:flex; flex-direction:column;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-shrink:0;">
                    <h3 id="n8n-modal-title" style="margin:0; font-size:18px;">Workflow Pipeline Structure</h3>
                    <button type="button" class="button button-secondary" id="n8n-close-modal">Close</button>
                </div>
                <div id="n8n-nodes-pipeline" style="flex:1; position:relative; overflow:hidden; min-height:0;">
                    <p>Loading nodes...</p>
                </div>
            </div>
        </div>
        <?php
    }

    public function proxy_get_workflows() {
        check_ajax_referer('n8n_manual_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);

        $settings = self::get_settings();
        $response = wp_remote_get($settings['url'] . '/api/v1/workflows', [
            'timeout' => 15,
            'headers' => ['X-N8N-API-KEY' => $settings['key'], 'Accept' => 'application/json']
        ]);

        if (is_wp_error($response)) wp_send_json_error(['message' => $response->get_error_message()]);

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            wp_send_json_error(['message' => $body['message'] ?? ('n8n returned HTTP ' . $code)]);
        }

        $workflows = $body['data'] ?? [];

        $saved_order = get_option('n8n_workflow_custom_order', []);
        if (!empty($saved_order) && is_array($saved_order)) {
            $ordered = []; $map = [];
            foreach ($workflows as $wf) $map[$wf['id']] = $wf;
            foreach ($saved_order as $id) { if (isset($map[$id])) { $ordered[] = $map[$id]; unset($map[$id]); } }
            foreach ($map as $wf) $ordered[] = $wf;
            $workflows = $ordered;
        }

        wp_send_json_success($workflows);
    }

    public function proxy_toggle_workflow() {
        check_ajax_referer('n8n_manual_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);

        $workflow_id = sanitize_text_field($_POST['workflow_id'] ?? '');
        if (empty($workflow_id)) wp_send_json_error(['message' => 'Missing workflow ID']);

        $activate    = rest_sanitize_boolean($_POST['activate'] ?? false);
        $settings    = self::get_settings();
        $endpoint    = $settings['url'] . '/api/v1/workflows/' . rawurlencode($workflow_id) . ($activate ? '/activate' : '/deactivate');

        $response = wp_remote_post($endpoint, [
            'timeout' => 15,
            'headers' => [
                'X-N8N-API-KEY' => $settings['key'],
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json'
            ],
            'body' => '{}'
        ]);

        if (is_wp_error($response)) wp_send_json_error(['message' => $response->get_error_message()]);

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $msg = $body['message'] ?? ('n8n returned HTTP ' . $code);
            wp_send_json_error(['message' => $msg]);
        }

        wp_send_json_success();
    }

    public function proxy_get_executions() {
        check_ajax_referer('n8n_manual_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);

        $settings = self::get_settings();
        $response = wp_remote_get($settings['url'] . '/api/v1/executions?limit=20', [
            'timeout' => 15,
            'headers' => ['X-N8N-API-KEY' => $settings['key'], 'Accept' => 'application/json']
        ]);

        if (is_wp_error($response)) wp_send_json_error(['message' => $response->get_error_message()]);

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            wp_send_json_error(['message' => $body['message'] ?? ('n8n returned HTTP ' . $code)]);
        }

        $executions = $body['data'] ?? [];

        $name_map = get_transient('n8n_workflow_name_map');
        if ($name_map === false) {
            $name_map = [];
            $wf_response = wp_remote_get($settings['url'] . '/api/v1/workflows?limit=250', [
                'timeout' => 15,
                'headers' => ['X-N8N-API-KEY' => $settings['key'], 'Accept' => 'application/json']
            ]);
            if (!is_wp_error($wf_response) && wp_remote_retrieve_response_code($wf_response) === 200) {
                $wf_body = json_decode(wp_remote_retrieve_body($wf_response), true);
                foreach (($wf_body['data'] ?? []) as $wf) {
                    $name_map[$wf['id']] = $wf['name'];
                }
            }
            set_transient('n8n_workflow_name_map', $name_map, 60);
        }

        foreach ($executions as &$exec) {
            $wid = $exec['workflowId'] ?? '';
            $exec['workflowName'] = $name_map[$wid] ?? ($wid !== '' ? $wid : 'Unknown');
        }
        unset($exec);

        wp_send_json_success($executions);
    }

    public function proxy_run_workflow() {
        check_ajax_referer('n8n_manual_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);

        $workflow_id = sanitize_text_field($_POST['workflow_id'] ?? '');
        if (empty($workflow_id)) wp_send_json_error(['message' => 'Missing workflow ID']);

        $settings    = self::get_settings();

        $response = wp_remote_post($settings['webhook'], [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['workflow_id' => $workflow_id])
        ]);

        if (is_wp_error($response)) wp_send_json_error(['message' => $response->get_error_message()]);

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            wp_send_json_error(['message' => 'Webhook returned HTTP ' . $code]);
        }

        wp_send_json_success();
    }

    public function proxy_save_workflow_order() {
        check_ajax_referer('n8n_manual_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);

        $order = $_POST['order'] ?? [];
        if (!is_array($order)) wp_send_json_error(['message' => 'Invalid order payload']);

        $order = array_filter($order, 'is_scalar');
        update_option('n8n_workflow_custom_order', array_map('sanitize_text_field', $order));
        wp_send_json_success();
    }
}
new Manually_Trigger_N8N_Workflow();