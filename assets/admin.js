/* global SNIO_Admin, jQuery */
(function ($) {
  "use strict";

  function setNotice($el, type, msg) {
    if (!$el.length) return;
    $el.removeClass("notice-success notice-error notice-info");
    if (type) $el.addClass(type);
    $el.text(String(msg || "")).show();
  }

  function toast(msg, ok) {
    var $t = $("#snio-toast");
    if (!$t.length) return;
    $t.removeClass("is-ok is-bad").addClass(ok ? "is-ok" : "is-bad").text(String(msg || "")).fadeIn(120);
    window.clearTimeout($t.data("timer"));
    $t.data("timer", window.setTimeout(function () {
      $t.fadeOut(220);
    }, 1800));
  }

  function post(action, payload) {
    return $.post(SNIO_Admin.ajaxUrl, $.extend({ action: action }, payload || {}));
  }

  function ajaxErrorMessage(xhr, fallback) {
    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
      return String(xhr.responseJSON.data.message);
    }
    if (xhr && xhr.responseText) {
      try {
        var parsed = JSON.parse(xhr.responseText);
        if (parsed && parsed.data && parsed.data.message) {
          return String(parsed.data.message);
        }
      } catch (e) {}
    }
    return String(fallback || ((SNIO_Admin && SNIO_Admin.i18n && SNIO_Admin.i18n.ajaxErr) ? SNIO_Admin.i18n.ajaxErr : "AJAX request failed."));
  }

  function readValue($el) {
    if ($el.is(":checkbox")) return $el.is(":checked") ? "1" : "0";
    var v = $el.val();
    return String(v == null ? "" : v);
  }

  function collectSettings(keys) {
    var out = {};
    $.each(keys, function (_, key) {
      var $el = $('.snio-qs[data-key="' + key + '"]').first();
      if (!$el.length) $el = $('.snio-qs-text[data-key="' + key + '"]').first();
      if ($el.length) out[key] = readValue($el);
    });
    return out;
  }

  function syncMirrors(settings) {
    $.each(settings || {}, function (key, val) {
      var boolVal = String(val) === "1" || val === true || val === 1;
      $('.snio-qs[data-key="' + key + '"]').prop("checked", boolVal);
      $('.snio-qs-text[data-key="' + key + '"]').val(String(val == null ? "" : val));
    });
  }

  function knownQuickSaveKeys() {
    return ["enabled", "serve_webp", "lazy_enabled", "lazy_skip_first"];
  }

  function scopeKeysFor($el) {
    var key = String($el.data("key") || "");
    if (key === "enabled" || key === "serve_webp") {
      return ["enabled", "serve_webp"];
    }
    if (key === "lazy_enabled" || key === "lazy_skip_first") {
      return ["lazy_enabled", "lazy_skip_first"];
    }
    return knownQuickSaveKeys();
  }

  function savingState($el, isSaving) {
    var $wrap = $el.closest(".snio-switchx, .snio-field, .snio-cardx");
    if (!$wrap.length) return;
    $wrap.toggleClass("snio-is-saving", !!isSaving);
  }

  function runQuickSave(keys, $origin, onDone) {
    keys = $.isArray(keys) && keys.length ? keys : knownQuickSaveKeys();
    savingState($origin, true);
    post("snio_quick_save", {
      _ajax_nonce: SNIO_Admin.nonceSave,
      settings: collectSettings(keys)
    }).done(function (resp) {
      if (resp && resp.success) {
        if (resp.data && resp.data.settings) syncMirrors(resp.data.settings);
        if (resp.data && resp.data.message) toast(resp.data.message, true);
        if (typeof onDone === "function") onDone(true, resp);
      } else {
        toast(resp && resp.data && resp.data.message ? resp.data.message : "Save failed.", false);
        if (typeof onDone === "function") onDone(false, resp);
      }
    }).fail(function (xhr) {
      toast(ajaxErrorMessage(xhr), false);
      if (typeof onDone === "function") onDone(false, xhr);
    }).always(function () {
      savingState($origin, false);
    });
  }

  $(function () {
    $(document).on("click", ".snio-qs-save", function () {
      var $btn = $(this);
      $btn.prop("disabled", true);
      runQuickSave(knownQuickSaveKeys(), $btn, function () {
        $btn.prop("disabled", false);
      });
    });

    $(document).on("change", ".snio-qs", function () {
      var $el = $(this);
      runQuickSave(scopeKeysFor($el), $el);
    });

    $(document).on("change blur", ".snio-qs-text", function () {
      var $el = $(this);
      runQuickSave(scopeKeysFor($el), $el);
    });
  });
  $(function () {
    var $btn = $("#snio-lazy-test");
    if (!$btn.length) return;
    var $res = $("#snio-lazy-test-result");

    $btn.on("click", function () {
      $btn.prop("disabled", true);
      setNotice($res, "notice-info", (SNIO_Admin && SNIO_Admin.i18n && SNIO_Admin.i18n.running) ? SNIO_Admin.i18n.running : "Running test…");
      post("snio_lazy_test", {
        _ajax_nonce: SNIO_Admin.nonce,
        url: (SNIO_Admin && SNIO_Admin.siteUrl) ? SNIO_Admin.siteUrl : ""
      }).done(function (resp) {
        if (resp && resp.success) {
          setNotice($res, "notice-success", resp.data && resp.data.message ? resp.data.message : "OK");
        } else {
          setNotice($res, "notice-error", resp && resp.data && resp.data.message ? resp.data.message : "The test failed.");
        }
      }).fail(function (xhr) {
        setNotice($res, "notice-error", ajaxErrorMessage(xhr));
      }).always(function () {
        $btn.prop("disabled", false);
      });
    });
  });

  $(function () {
    var $btn = $("#snio-lazy-clear-cache");
    if (!$btn.length) return;
    var $res = $("#snio-lazy-cache-result");

    $btn.on("click", function () {
      $btn.prop("disabled", true);
      setNotice($res, "notice-info", (SNIO_Admin && SNIO_Admin.i18n && SNIO_Admin.i18n.cacheClearing) ? SNIO_Admin.i18n.cacheClearing : "Clearing cache…");
      post("snio_clear_cache", {
        _ajax_nonce: SNIO_Admin.nonceClearCache
      }).done(function (resp) {
        if (resp && resp.success) {
          setNotice($res, "notice-success", resp.data && resp.data.message ? resp.data.message : "Cache cleared.");
        } else {
          setNotice($res, "notice-error", resp && resp.data && resp.data.message ? resp.data.message : "Failed.");
        }
      }).fail(function (xhr) {
        setNotice($res, "notice-error", ajaxErrorMessage(xhr));
      }).always(function () {
        $btn.prop("disabled", false);
      });
    });
  });

  $(function () {
    var $start = $("#snio-bulk-start");
    if (!$start.length) return;

    var $cancel = $("#snio-bulk-cancel");
    var $status = $("#snio-bulk-status");
    var $log = $("#snio-bulk-log");
    var $bar = $("#snio-bulk-bar");
    var running = false;

    function addLine(text, isError) {
      if (!$log.length || !text) return;
      $log.show();
      var safe = $("<div />").text(String(text)).html();
      $log.append('<div' + (isError ? ' class="is-error"' : '') + '>' + safe + '</div>');
      $log.scrollTop($log.prop("scrollHeight"));
    }

    function setProgress(done, total) {
      done = parseInt(done, 10) || 0;
      total = Math.max(1, parseInt(total, 10) || 1);
      var pct = Math.max(0, Math.min(100, Math.round((done / total) * 100)));
      if ($bar.length) $bar.css("width", pct + "%");
    }

    function setConvertedCount(count) {
      var $count = $("#snio-bulk-converted-count");
      if (!$count.length) return;
      count = Math.max(0, parseInt(count, 10) || 0);
      $count.text(count + " converted");
    }

    function finish(ok, msg, noticeType) {
      running = false;
      var shouldStayDisabled = $start.data("disableAfterFinish") === 1 || $start.data("disableAfterFinish") === true;
      var fallbackType = ok === true ? "notice-success" : (ok === false ? "notice-error" : "notice-info");
      var fallbackMsg = ok === true ? "Done." : (ok === false ? "Failed." : "Ready.");
      $start.prop("disabled", shouldStayDisabled);
      $cancel.hide();
      setNotice($status, noticeType || fallbackType, msg || fallbackMsg);
    }

    function tick(cmd) {
      post("snio_bulk", {
        _ajax_nonce: SNIO_Admin.nonceBulk,
        cmd: cmd || "run"
      }).done(function (resp) {
        if (!(resp && resp.success)) {
          finish(false, resp && resp.data && resp.data.message ? resp.data.message : "Bulk failed.");
          return;
        }

        var data = resp.data || {};
        var progress = data.progress || {};
        setProgress(progress.done || 0, progress.total || 1);
        if (typeof data.history_used !== "undefined") {
          setConvertedCount(data.history_used);
        } else if (typeof progress.ok !== "undefined") {
          setConvertedCount(progress.ok);
        }
        setNotice($status, "notice-info", data.message || ((SNIO_Admin && SNIO_Admin.i18n && SNIO_Admin.i18n.bulkRunning) ? SNIO_Admin.i18n.bulkRunning : "Bulk run in progress…"));

        $.each(data.converted || [], function (_, id) {
          addLine("Converted attachment #" + id, false);
        });
        $.each(data.errors || [], function (_, row) {
          addLine("Attachment #" + (row && row.id ? row.id : "?") + ": " + (row && row.message ? row.message : "Unknown error"), true);
        });

        if (data.finished) {
          if (data.no_eligible || (progress.total || 0) === 0) {
            if ($log.length) $log.empty().hide();
            $start.data("disableAfterFinish", 1);
            finish(null, data.message || "No eligible images for conversion.", "notice-info");
            return;
          }
          finish(true, data.message || ((SNIO_Admin && SNIO_Admin.i18n && SNIO_Admin.i18n.bulkDone) ? SNIO_Admin.i18n.bulkDone : "Bulk optimize completed."));
          return;
        }

        if (running) {
          window.setTimeout(function () { tick("run"); }, 250);
        }
      }).fail(function (xhr) {
        finish(false, ajaxErrorMessage(xhr));
      });
    }

    $start.on("click", function () {
      if (running || $start.prop("disabled")) return;
      running = true;
      $start.prop("disabled", true);
      $cancel.show();
      if ($log.length) $log.empty().hide();
      setNotice($status, "notice-info", (SNIO_Admin && SNIO_Admin.i18n && SNIO_Admin.i18n.bulkStart) ? SNIO_Admin.i18n.bulkStart : "Starting bulk optimize…");
      tick("start");
    });

    $cancel.on("click", function () {
      if (!running) return;
      running = false;
      post("snio_bulk", {
        _ajax_nonce: SNIO_Admin.nonceBulk,
        cmd: "cancel"
      }).always(function () {
        $start.prop("disabled", false);
        $cancel.hide();
        setNotice($status, "notice-info", "Cancelled.");
      });
    });
  });

  $(function () {
    var $search = $("#snio-log-search");
    var $level = $("#snio-log-level");
    var $table = $("#snio-log-table");
    if (!$table.length) return;

    function filterLogs() {
      var q = String($search.val() || "").toLowerCase();
      var lvl = String($level.val() || "").toLowerCase();
      $table.find("tbody tr").each(function () {
        var $tr = $(this);
        var text = $tr.text().toLowerCase();
        var rowLevel = String($tr.find("td").eq(1).text() || "").toLowerCase();
        $tr.toggle((!q || text.indexOf(q) !== -1) && (!lvl || rowLevel === lvl));
      });
    }

    $search.on("input", filterLogs);
    $level.on("change", filterLogs);

    $(document).on("click", ".snio-copy-logs", function () {
      var rows = [];
      $table.find("tbody tr:visible").each(function () {
        rows.push($(this).text().replace(/\s+/g, " ").trim());
      });
      if (!rows.length || !navigator.clipboard) {
        toast(rows.length ? "Clipboard is not available." : "No visible logs to copy.", false);
        return;
      }
      navigator.clipboard.writeText(rows.join("\n")).then(function () {
        toast("Logs copied.", true);
      }).catch(function () {
        toast("Copy failed.", false);
      });
    });
  });

  $(function () {
    $(document).on("click", ".snio-copy-diagnostics", function () {
      var rows = [];
      $(".snio-table--mini tbody tr").each(function () {
        rows.push($(this).text().replace(/\s+/g, " ").trim());
      });
      if (!rows.length || !navigator.clipboard) {
        toast(rows.length ? "Clipboard is not available." : "No diagnostics to copy.", false);
        return;
      }
      navigator.clipboard.writeText(rows.join("\n")).then(function () {
        toast("Diagnostics copied.", true);
      }).catch(function () {
        toast("Copy failed.", false);
      });
    });
  });
})(jQuery);
