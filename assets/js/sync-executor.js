/*
 * Deploy Extender Plugin for October CMS
 * SyncExecutor — Step-based sync progress UI
 *
 * @author     Pear Interactive <hello@pear.pl>
 * @link       https://github.com/pearpl/OctoberCMS-DeployExtender-Plugin
 * @license    proprietary
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
        this.retries = 0;
        this.maxRetries = 2;
        this.requestTimeout = 180000; // 3 min
    };

    SyncExecutor.prototype.start = function () {
        this.$form.hide();
        this.$progress.show();
        this.currentStep = 0;
        this.retries = 0;
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

        if (!this.$log.find('[data-index="' + this.currentStep + '"]').length) {
            this.addLogEntry(step.label);
        }

        var timeoutId = setTimeout(function () {
            if (self.retries < self.maxRetries) {
                self.retries++;
                self.updateLogProgress(self.currentStep, 'Timeout, retrying (' + self.retries + '/' + self.maxRetries + ')...', null);
                setTimeout(function () { self.executeStep(); }, 1000);
            } else {
                self.updateLogEntry(self.currentStep, 'error', 'Request timed out after ' + self.maxRetries + ' retries');
                self.onFailed();
            }
        }, self.requestTimeout);

        $.request(this.handler, {
            data: requestData,
            progressBar: false,
            handleFlashMessage: function () { return true; },
            handleErrorMessage: function (msg) {
                clearTimeout(timeoutId);
                if (self.retries < self.maxRetries) {
                    self.retries++;
                    self.updateLogProgress(self.currentStep, 'Error, retrying (' + self.retries + '/' + self.maxRetries + ')...', null);
                    setTimeout(function () { self.executeStep(); }, 2000);
                } else {
                    self.updateLogEntry(self.currentStep, 'error', msg);
                    self.onFailed();
                }
                return true;
            },
            error: function (jqXHR) {
                clearTimeout(timeoutId);
                if (self.retries < self.maxRetries) {
                    self.retries++;
                    self.updateLogProgress(self.currentStep, 'Server error (' + jqXHR.status + '), retrying (' + self.retries + '/' + self.maxRetries + ')...', null);
                    setTimeout(function () { self.executeStep(); }, 2000);
                } else {
                    var msg = 'Server error (' + jqXHR.status + ')';
                    try { msg = jqXHR.getResponseHeader('X-OCTOBER-ERROR-MESSAGE') || msg; } catch(e) {}
                    self.updateLogEntry(self.currentStep, 'error', msg);
                    self.onFailed();
                }
            },
            success: function (data) {
                clearTimeout(timeoutId);
                self.retries = 0;
                if (data.continue) {
                    self.updateLogProgress(self.currentStep, data.message || '', data.progress || null);
                    setTimeout(function () { self.executeStep(); }, 200);
                } else {
                    var status = data.skipped ? 'skipped' : 'success';
                    if (data.progress) {
                        self.updateLogProgress(self.currentStep, '', data.progress);
                    }
                    self.updateLogEntry(self.currentStep, status, data.message || '');
                    self.currentStep++;
                    setTimeout(function () { self.executeStep(); }, 300);
                }
            }
        });
    };

    SyncExecutor.prototype.addLogEntry = function (label) {
        var html = '<div class="sync-log-entry" data-index="' + this.currentStep + '">'
            + '<span class="sync-log-icon"><i class="icon-spinner icon-spin"></i></span> '
            + '<span class="sync-log-label">' + label + '</span>'
            + '<span class="sync-log-detail text-muted"></span>'
            + '<div class="sync-log-progress" style="display:none;margin:4px 0 2px 22px">'
            + '<div style="background:#e9ecef;border-radius:4px;height:6px;width:100%;overflow:hidden">'
            + '<div class="sync-bar" style="background:#007bff;height:100%;width:0%;transition:width .3s"></div>'
            + '</div>'
            + '<div class="sync-log-stats text-muted" style="font-size:11px;margin-top:2px"></div>'
            + '</div>'
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

    SyncExecutor.prototype.updateLogProgress = function (index, detail, progress) {
        var $entry = this.$log.find('[data-index="' + index + '"]');
        $entry.find('.sync-log-detail').text(' \u2014 ' + detail);

        if (progress && progress.percent !== undefined) {
            var $bar = $entry.find('.sync-log-progress');
            $bar.show();
            $bar.find('.sync-bar').css('width', progress.percent + '%');

            var stats = progress.bytes + ' / ' + progress.totalBytes + '  \u2022  ' + progress.speed;
            $bar.find('.sync-log-stats').text(stats);
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
