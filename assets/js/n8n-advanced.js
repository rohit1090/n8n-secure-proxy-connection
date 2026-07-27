jQuery(document).ready(function($) {
    var nonce = n8nData.nonce;
    var esc = window.n8nEsc;

    /* =========================================================
     * NODE PIPELINE CANVAS
     * ========================================================= */

    $(document).on('click', '.inspect-wf-btn', function() {
        var wfId = $(this).data('id');
        $('#n8n-node-modal').css('display', 'flex');
        $('#n8n-nodes-pipeline').html('<p style="color:#646970;"><em>Fetching node graph from n8n...</em></p>');
        $.ajax({
            url: n8nData.ajaxurl, type: 'POST',
            data: { action: 'n8n_proxy_get_workflow_details', nonce: nonce, workflow_id: wfId },
            success: function(res) {
                if (!res.success || !res.data.nodes) {
                    var reason = (res.data && res.data.message) ? res.data.message : 'Could not retrieve node data.';
                    $('#n8n-nodes-pipeline').html('<p style="color:#d63638;">' + esc(reason) + '</p>');
                    return;
                }
                $('#n8n-modal-title').text('Pipeline: ' + (res.data.name || wfId));
                renderWorkflowCanvas(res.data);
            },
            error: function() {
                $('#n8n-nodes-pipeline').html('<p style="color:#d63638;">Server communication error.</p>');
            }
        });
    });

    function renderWorkflowCanvas(workflowData) {
        var nodes = workflowData.nodes || [];
        var connections = workflowData.connections || {};

        if (!nodes.length) {
            $('#n8n-nodes-pipeline').html('<p style="color:#646970;">This workflow has no nodes.</p>');
            return;
        }

        var NODE_W = 180, NODE_H = 56, PAD = 80;
        var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;

        $.each(nodes, function(i, n) {
            var pos = n.position || [0, 0];
            minX = Math.min(minX, pos[0]);
            minY = Math.min(minY, pos[1]);
            maxX = Math.max(maxX, pos[0] + NODE_W);
            maxY = Math.max(maxY, pos[1] + NODE_H);
        });

        var canvasW = Math.max((maxX - minX) + PAD * 2, 400);
        var canvasH = Math.max((maxY - minY) + PAD * 2, 300);

        var posByName = {};
        $.each(nodes, function(i, n) {
            var pos = n.position || [0, 0];
            posByName[n.name] = { x: pos[0] - minX + PAD, y: pos[1] - minY + PAD };
        });

        var html = '';
        html += '<div class="n8n-canvas-controls">';
        html += '<button type="button" class="button button-secondary" id="n8n-zoom-out">−</button>';
        html += '<button type="button" class="button button-secondary" id="n8n-zoom-reset">Fit</button>';
        html += '<button type="button" class="button button-secondary" id="n8n-zoom-in">+</button>';
        html += '</div>';
        html += '<div class="n8n-canvas-viewport" id="n8n-canvas-viewport">';
        html += '<div class="n8n-canvas-inner" id="n8n-canvas-inner" style="width:' + canvasW + 'px; height:' + canvasH + 'px;">';
        html += '<svg class="n8n-canvas-svg" width="' + canvasW + '" height="' + canvasH + '">';

        $.each(connections, function(sourceName, outputTypes) {
            var sp = posByName[sourceName];
            if (!sp) return;
            var x1 = sp.x + NODE_W, y1 = sp.y + NODE_H / 2;
            $.each(outputTypes, function(typeName, branches) {
                $.each(branches, function(bi, targets) {
                    if (!targets) return;
                    $.each(targets, function(ti, t) {
                        var tp = posByName[t.node];
                        if (!tp) return;
                        var x2 = tp.x, y2 = tp.y + NODE_H / 2;
                        var midX = (x1 + x2) / 2;
                        html += '<path d="M' + x1 + ',' + y1 + ' C' + midX + ',' + y1 + ' ' + midX + ',' + y2 + ' ' + x2 + ',' + y2 + '" stroke="#a7aaad" stroke-width="2" fill="none" />';
                    });
                });
            });
        });

        html += '</svg>';

        $.each(nodes, function(i, n) {
            var p = posByName[n.name];
            var shortType = (n.type || '').replace('n8n-nodes-base.', '');
            html += '<div class="n8n-canvas-node" style="left:' + p.x + 'px; top:' + p.y + 'px; width:' + NODE_W + 'px;" title="' + esc(n.name) + '">';
            html += '<strong>' + esc(n.name) + '</strong><span>' + esc(shortType) + '</span>';
            html += '</div>';
        });

        html += '</div></div>';

        $('#n8n-nodes-pipeline').html(html);

        var scale = 1;
        var $inner = $('#n8n-canvas-inner');
        var $viewport = $('#n8n-canvas-viewport');

        function applyZoom() { $inner.css('transform', 'scale(' + scale + ')'); }

        function fitToView() {
            var vw = $viewport.width(), vh = $viewport.height();
            scale = Math.max(Math.min(vw / canvasW, vh / canvasH, 1), 0.15);
            applyZoom();
            $viewport.scrollLeft(0);
            $viewport.scrollTop(0);
        }

        $('#n8n-zoom-in').on('click', function() { scale = Math.min(scale + 0.1, 2); applyZoom(); });
        $('#n8n-zoom-out').on('click', function() { scale = Math.max(scale - 0.1, 0.15); applyZoom(); });
        $('#n8n-zoom-reset').on('click', fitToView);

        var isDragging = false, startX, startY, scrollLeft, scrollTop;
        $viewport.on('mousedown', function(e) {
            isDragging = true;
            $viewport.addClass('dragging');
            startX = e.pageX; startY = e.pageY;
            scrollLeft = $viewport.scrollLeft(); scrollTop = $viewport.scrollTop();
        });
        $(document).on('mousemove.n8ncanvas', function(e) {
            if (!isDragging) return;
            $viewport.scrollLeft(scrollLeft - (e.pageX - startX));
            $viewport.scrollTop(scrollTop - (e.pageY - startY));
        });
        $(document).on('mouseup.n8ncanvas', function() {
            isDragging = false;
            $viewport.removeClass('dragging');
        });

        fitToView();
    }

    function teardownCanvasHandlers() {
        $(document).off('.n8ncanvas');
    }

    $('#n8n-close-modal').on('click', function() {
        $('#n8n-node-modal').hide();
        teardownCanvasHandlers();
    });
    $(window).on('click', function(e) {
        if ($(e.target).is('#n8n-node-modal')) {
            $('#n8n-node-modal').hide();
            teardownCanvasHandlers();
        }
    });
});