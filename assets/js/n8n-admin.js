window.n8nEsc = function(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};

window.n8nFormatDate = function(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
};

jQuery(document).ready(function($) {
    var nonce = n8nData.nonce;
    var esc = window.n8nEsc;

    $('.n8n-tab').on('click', function(e) {
        e.preventDefault();
        $('.n8n-tab').removeClass('active');
        $(this).addClass('active');
        $('.n8n-tab-content').hide();
        $('#' + $(this).data('target')).show();

        if ($(this).data('target') === 'tab-logs') loadExecutions();
    });

    function loadWorkflows() {
        $('#n8n-workflow-container').html('<p style="color: #646970;"><em>Fetching workflows...</em></p>');
        $.ajax({
            url: n8nData.ajaxurl, type: 'POST', data: { action: 'n8n_proxy_get_workflows', nonce: nonce },
            success: function(res) {
                if (res.success) renderCards(res.data);
                else $('#n8n-workflow-container').html('<div class="notice notice-error inline"><p>' + esc(res.data.message) + '</p></div>');
            },
            error: function() {
                $('#n8n-workflow-container').html('<div class="notice notice-error inline"><p>Server communication error.</p></div>');
            }
        });
    }

    function renderCards(workflows) {
        if (!workflows.length) { $('#n8n-workflow-container').html('<p>No workflows found.</p>'); return; }
        var html = '';
        $.each(workflows, function(i, wf) {
            var isChecked = wf.active ? 'checked' : '';
            var id = esc(wf.id);
            html += '<div class="n8n-card" data-id="' + id + '">';
            html += '<div class="n8n-card-left">';
            html += '<span class="dashicons dashicons-menu n8n-drag-handle" title="Drag to reorder"></span>';
            html += '<div style="width:40px;height:40px;background:#f0f6fc;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#2271b1;"><span class="dashicons dashicons-randomize"></span></div>';
            html += '<div><strong>' + esc(wf.name) + '</strong><br><span style="color:#646970;font-size:12px;">ID: ' + id + '</span></div>';
            html += '</div>';
            html += '<div style="display:flex;align-items:center;gap:15px;">';
            html += '<button class="button button-secondary inspect-wf-btn" data-id="' + id + '" title="Inspect Nodes Pipeline">Inspect Nodes</button>';
            html += '<label class="n8n-switch" title="Toggle Active State"><input type="checkbox" class="toggle-wf-btn" data-id="' + id + '" ' + isChecked + '><span class="n8n-slider"></span></label>';
            html += '<button class="button button-primary run-wf-btn" data-id="' + id + '">Trigger Workflow</button>';
            html += '<span class="status-msg-wf" data-msg-for="' + id + '" style="font-size:13px;"></span>';
            html += '</div></div>';
        });
        $('#n8n-workflow-container').html(html);

        $('#n8n-workflow-container').sortable({
            handle: '.n8n-drag-handle',
            placeholder: 'ui-sortable-placeholder',
            update: function() {
                var ids = [];
                $('#n8n-workflow-container .n8n-card').each(function() { ids.push($(this).data('id')); });
                $('#n8n-save-status').text('Saving order...');
                $.ajax({
                    url: n8nData.ajaxurl, type: 'POST',
                    data: { action: 'n8n_save_workflow_order', nonce: nonce, order: ids },
                    success: function(res) {
                        if (res.success) {
                            $('#n8n-save-status').text('Order saved!');
                            setTimeout(function() { $('#n8n-save-status').text(''); }, 2000);
                        }
                    }
                });
            }
        });
    }

    $(document).on('change', '.toggle-wf-btn', function() {
        var cb = $(this), id = cb.data('id'), act = cb.is(':checked');
        var msg = $('.status-msg-wf[data-msg-for="' + id + '"]');
        cb.prop('disabled', true);
        msg.css('color', '#646970').text('Updating...');

        $.ajax({
            url: n8nData.ajaxurl, type: 'POST',
            data: { action: 'n8n_proxy_toggle_workflow', nonce: nonce, workflow_id: id, activate: act ? 1 : 0 },
            success: function(res) {
                cb.prop('disabled', false);
                if (res.success) {
                    msg.css('color', '#008a20').text(act ? 'Activated' : 'Deactivated');
                    setTimeout(function() { msg.text(''); }, 3000);
                } else {
                    cb.prop('checked', !act);
                    var reason = (res.data && res.data.message) ? res.data.message : 'Unknown error';
                    msg.css('color', '#d63638').text('Failed: ' + reason);
                }
            },
            error: function() {
                cb.prop('disabled', false).prop('checked', !act);
                msg.css('color', '#d63638').text('Server communication error.');
            }
        });
    });

    function loadExecutions() {
        $('#n8n-logs-container').html('<p style="color: #646970;"><em>Loading execution history...</em></p>');
        $.ajax({
            url: n8nData.ajaxurl, type: 'POST', data: { action: 'n8n_proxy_get_executions', nonce: nonce },
            success: function(res) {
                if (!res.success) {
                    $('#n8n-logs-container').html('<div class="notice notice-error inline"><p>' + esc(res.data.message) + '</p></div>');
                    return;
                }
                if (!res.data.length) { $('#n8n-logs-container').html('<p>No execution logs found.</p>'); return; }
                var table = '<table class="n8n-log-table"><thead><tr><th>ID</th><th>Workflow</th><th>Status</th><th>Started At</th></tr></thead><tbody>';
                $.each(res.data, function(i, log) {
                    var sc = log.finished ? '#008a20' : '#d63638', st = log.finished ? 'Success' : 'Running/Failed';
                    table += '<tr><td>' + esc(log.id) + '</td><td>' + esc(log.workflowName) + '</td><td><span style="color:' + sc + ';font-weight:600;">● ' + st + '</span></td><td>' + esc(window.n8nFormatDate(log.startedAt)) + '</td></tr>';
                });
                table += '</tbody></table>';
                $('#n8n-logs-container').html(table);
            },
            error: function() {
                $('#n8n-logs-container').html('<div class="notice notice-error inline"><p>Server communication error.</p></div>');
            }
        });
    }

    $(document).on('click', '.run-wf-btn', function(e) {
        e.preventDefault();
        var btn = $(this), id = btn.data('id'), msg = $('.status-msg-wf[data-msg-for="' + id + '"]');
        btn.prop('disabled', true).text('Triggering...');
        msg.css('color', '#646970').text('Running...');
        $.ajax({
            url: n8nData.ajaxurl, type: 'POST',
            data: { action: 'n8n_proxy_run_workflow', nonce: nonce, workflow_id: id },
            success: function(res) {
                btn.prop('disabled', false).text('Trigger Workflow');
                if (res.success) {
                    msg.css('color', '#008a20').text('Triggered!');
                    setTimeout(function() { msg.text(''); }, 4000);
                } else {
                    msg.css('color', '#d63638').text('Failed: ' + ((res.data && res.data.message) || 'Unknown error'));
                }
            },
            error: function() { btn.prop('disabled', false).text('Trigger Workflow'); msg.css('color', '#d63638').text('Error'); }
        });
    });

    $('#n8n-fetch-btn').on('click', loadWorkflows);
    $('#n8n-refresh-logs').on('click', loadExecutions);

    loadWorkflows();
});