/*
 * SyncExecutor — Step-based sync progress UI
 *
 * Executes sync operations step-by-step via AJAX,
 * showing a live progress log in the popup window.
 */
+function ($) { "use strict";

    var SyncExecutor = function (config) {
        this.steps = config.steps || [];
        this.serverId = config.serverId;
        this.handler = config.handler;
        this.initData = config.initData || {};
        this.$log = $(config.logSelector);
        this.$form = $(config.formSelector);
        this.$progress = $(config.progressSelector);
        this.$footer = $(config.footerSelector);
        this.currentStep = 0;
    };

    SyncExecutor.prototype.start = function () {
        this.$form.hide();
        this.$progress.show();
        this.currentStep = 0;
        this.executeStep();
    };

    SyncExecutor.prototype.executeStep = function () {
        if (this.currentStep >= this.steps.length) {
            this.onComplete();
            return;
        }

        var self = this,
            step = this.steps[this.currentStep],
            requestData = {
                server_id: this.serverId,
                step: step.code
            };

        // Pass form data only on init step
        if (step.code === 'init') {
            $.extend(requestData, this.initData);
        }

        this.addLogEntry(step.label);

        $.request(this.handler, {
            data: requestData,
            progressBar: false,
            handleFlashMessage: function () { return true; },
            handleErrorMessage: function (msg) {
                self.updateLogEntry(self.currentStep, 'error', msg);
                self.onFailed();
                return true;
            },
            success: function (data) {
                var status = data.skipped ? 'skipped' : 'success';
                self.updateLogEntry(self.currentStep, status, data.message || '');
                self.currentStep++;
                setTimeout(function () { self.executeStep(); }, 300);
            }
        });
    };

    SyncExecutor.prototype.addLogEntry = function (label) {
        var html = '<div class="sync-log-entry" data-index="' + this.currentStep + '">'
            + '<span class="sync-log-icon"><i class="icon-spinner icon-spin"></i></span> '
            + '<span class="sync-log-label">' + label + '</span>'
            + '<span class="sync-log-detail text-muted"></span>'
            + '</div>';
        this.$log.append(html);
        this.$log.scrollTop(this.$log[0].scrollHeight);
    };

    SyncExecutor.prototype.updateLogEntry = function (index, status, detail) {
        var $entry = this.$log.find('[data-index="' + index + '"]'),
            icons = {
                success: '<i class="icon-check" style="color:#28a745"></i>',
                skipped: '<i class="icon-minus-circle" style="color:#999"></i>',
                error:   '<i class="icon-times" style="color:#dc3545"></i>'
            };

        $entry.find('.sync-log-icon').html(icons[status] || '');

        if (detail) {
            $entry.find('.sync-log-detail').text(' \u2014 ' + detail);
        }

        this.$log.scrollTop(this.$log[0].scrollHeight);
    };

    SyncExecutor.prototype.onComplete = function () {
        this.$log.append(
            '<div class="sync-log-entry" style="margin-top:12px;color:#28a745;font-weight:600">'
            + '<i class="icon-check-circle"></i> Sync completed successfully!</div>'
        );
        this.$footer.html(
            '<button type="button" class="btn btn-success" onclick="location.reload()">'
            + '<i class="icon-check"></i> Done</button>'
        );
    };

    SyncExecutor.prototype.onFailed = function () {
        this.$footer.html(
            '<button type="button" class="btn btn-default" onclick="location.reload()">'
            + 'Close</button>'
        );
    };

    if ($.oc === undefined) $.oc = {};
    $.oc.SyncExecutor = SyncExecutor;

}(window.jQuery);
