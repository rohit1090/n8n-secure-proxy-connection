<?php
/**
 * Module Name: n8n Advanced Features (Node Canvas)
 * Description: Fetches full workflow node/connection data for the pan/zoom
 *              canvas shown in the Node Pipeline modal.
 */
if (!defined('ABSPATH')) exit;

class N8N_Advanced_Features {
    public function __construct() {
        add_action('wp_ajax_n8n_proxy_get_workflow_details', [$this, 'proxy_get_workflow_details']);
    }

    public function proxy_get_workflow_details() {
        check_ajax_referer('n8n_manual_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);

        $workflow_id = sanitize_text_field($_POST['workflow_id'] ?? '');
        if (empty($workflow_id)) wp_send_json_error(['message' => 'Missing workflow ID']);

        $settings = Manually_Trigger_N8N_Workflow::get_settings();
        $response = wp_remote_get($settings['url'] . '/api/v1/workflows/' . rawurlencode($workflow_id), [
            'timeout' => 15,
            'headers' => ['X-N8N-API-KEY' => $settings['key'], 'Accept' => 'application/json']
        ]);

        if (is_wp_error($response)) wp_send_json_error(['message' => $response->get_error_message()]);

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            wp_send_json_error(['message' => $body['message'] ?? ('n8n returned HTTP ' . $code)]);
        }

        wp_send_json_success($body);
    }
}
new N8N_Advanced_Features();