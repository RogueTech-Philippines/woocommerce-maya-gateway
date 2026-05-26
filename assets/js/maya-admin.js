/* global jQuery, wcMayaAdmin */
(function ($) {
  "use strict";

  const sprintf = function (template, value) {
    return template.replace(/%d|%s/, value);
  };

  function attachKeyToggles() {
    $("input.wc-maya-key-input").each(function () {
      const $input = $(this);

      if ($input.data("wcMayaToggleAttached")) {
        return;
      }
      $input.data("wcMayaToggleAttached", true);

      const $wrap = $('<span class="wc-maya-key-wrap"></span>');
      const $btn = $(
        '<button type="button" class="button button-secondary wc-maya-toggle-key" aria-pressed="false">' +
          wcMayaAdmin.i18n.show +
          "</button>",
      );

      $input.wrap($wrap);
      $input.after($btn);

      $btn.on("click", function () {
        const isPassword = $input.attr("type") === "password";
        $input.attr("type", isPassword ? "text" : "password");
        $btn
          .attr("aria-pressed", isPassword ? "true" : "false")
          .text(isPassword ? wcMayaAdmin.i18n.hide : wcMayaAdmin.i18n.show);
      });
    });
  }

  function renderProbeLine(label, probe, okTemplate) {
    const $li = $('<li class="wc-maya-probe"></li>');
    const $label = $("<strong></strong>").text(label + ": ");
    $li.append($label);

    if (probe && probe.ok) {
      const detail = probe.checkoutId
        ? sprintf(okTemplate, probe.checkoutId)
        : sprintf(okTemplate, probe.webhookCount || 0);
      $li.addClass("wc-maya-ok").append(document.createTextNode(detail));
    } else {
      const message =
        (probe && probe.message) || wcMayaAdmin.i18n.unexpectedResponse;
      $li.addClass("wc-maya-error").append(document.createTextNode(message));
    }
    return $li;
  }

  function renderResult($container, data) {
    $container.empty();

    const env =
      data && data.environment === "sandbox"
        ? wcMayaAdmin.i18n.envSandbox
        : wcMayaAdmin.i18n.envProduction;
    $container.append($('<p class="wc-maya-env"></p>').text(env));

    const $list = $('<ul class="wc-maya-probe-list"></ul>');
    $list.append(
      renderProbeLine(
        wcMayaAdmin.i18n.publicKeyLabel,
        data && data.public_key,
        wcMayaAdmin.i18n.publicKeyOk,
      ),
    );
    $list.append(
      renderProbeLine(
        wcMayaAdmin.i18n.secretKeyLabel,
        data && data.secret_key,
        wcMayaAdmin.i18n.secretKeyOk,
      ),
    );
    $container.append($list);
  }

  function attachTestConnection() {
    const $btn = $("#wc-maya-test-connection");
    const $spinner = $("#wc-maya-test-connection-spinner");
    const $result = $("#wc-maya-test-connection-result");

    if (!$btn.length || $btn.data("wcMayaBound")) {
      return;
    }
    $btn.data("wcMayaBound", true);

    $btn.on("click", function () {
      $result.empty().append($("<p></p>").text(wcMayaAdmin.i18n.testing));
      $spinner.addClass("is-active");
      $btn.prop("disabled", true);

      const payload = {
        action: wcMayaAdmin.actions.testConnection,
        nonce: wcMayaAdmin.nonce,
        public_key: $("#woocommerce_maya_checkout_public_key").val() || "",
        secret_key: $("#woocommerce_maya_checkout_secret_key").val() || "",
        is_sandbox: $("#woocommerce_maya_checkout_is_sandbox").is(":checked")
          ? "yes"
          : "no",
        debug_log: $("#woocommerce_maya_checkout_debug_log").is(":checked")
          ? "yes"
          : "no",
      };

      $.post(wcMayaAdmin.ajaxUrl, payload)
        .done(function (response) {
          if (response && response.success && response.data) {
            renderResult($result, response.data);
            return;
          }
          const message =
            response && response.data && response.data.message
              ? response.data.message
              : wcMayaAdmin.i18n.unexpectedResponse;
          $result
            .empty()
            .append($('<p class="wc-maya-error"></p>').text(message));
        })
        .fail(function (xhr) {
          const message =
            xhr &&
            xhr.responseJSON &&
            xhr.responseJSON.data &&
            xhr.responseJSON.data.message
              ? xhr.responseJSON.data.message
              : xhr.statusText || wcMayaAdmin.i18n.unexpectedResponse;
          $result
            .empty()
            .append($('<p class="wc-maya-error"></p>').text(message));
        })
        .always(function () {
          $spinner.removeClass("is-active");
          $btn.prop("disabled", false);
        });
    });
  }

  function attachSimulator() {
    const $btn = $("#wc-maya-simulate-webhook");
    const $spinner = $("#wc-maya-simulate-spinner");
    const $result = $("#wc-maya-simulate-result");
    const $orderId = $("#wc-maya-simulate-order-id");
    const $status = $("#wc-maya-simulate-status");

    if (!$btn.length || $btn.data("wcMayaBound")) {
      return;
    }
    $btn.data("wcMayaBound", true);

    $btn.on("click", function () {
      $result.empty();
      $spinner.addClass("is-active");
      $btn.prop("disabled", true);

      const payload = {
        action: wcMayaAdmin.actions.simulateWebhook,
        nonce: wcMayaAdmin.nonce,
        order_id: $orderId.val() || "",
        status: $status.val() || "",
      };

      $.post(wcMayaAdmin.ajaxUrl, payload)
        .done(function (response) {
          if (response && response.success && response.data) {
            const summary =
              "HTTP " +
              response.data.status +
              " · " +
              (response.data.body && response.data.body.received
                ? wcMayaAdmin.i18n.simulateAccepted
                : wcMayaAdmin.i18n.simulateRejected);
            $result
              .empty()
              .append($('<p class="wc-maya-ok"></p>').text(summary))
              .append(
                $("<pre></pre>").text(
                  JSON.stringify(response.data.body, null, 2),
                ),
              );
            return;
          }
          const message =
            response && response.data && response.data.message
              ? response.data.message
              : wcMayaAdmin.i18n.unexpectedResponse;
          $result
            .empty()
            .append($('<p class="wc-maya-error"></p>').text(message));
        })
        .fail(function (xhr) {
          const message =
            xhr &&
            xhr.responseJSON &&
            xhr.responseJSON.data &&
            xhr.responseJSON.data.message
              ? xhr.responseJSON.data.message
              : xhr.statusText || wcMayaAdmin.i18n.unexpectedResponse;
          $result
            .empty()
            .append($('<p class="wc-maya-error"></p>').text(message));
        })
        .always(function () {
          $spinner.removeClass("is-active");
          $btn.prop("disabled", false);
        });
    });
  }

  function renderWebhookStatus($table, rows) {
    const $tbody = $table.find("tbody").empty();

    if (!rows || rows.length === 0) {
      $tbody.append(
        $("<tr></tr>").append(
          $('<td colspan="4"></td>').text(wcMayaAdmin.i18n.webhookStatusEmpty),
        ),
      );
      return;
    }

    rows.forEach(function (row) {
      const $tr = $("<tr></tr>");
      $tr.append($("<td></td>").append($("<code></code>").text(row.name || "")));
      $tr.append($("<td></td>").text(row.callbackUrl || ""));
      $tr.append($("<td></td>").text(row.createdAt || ""));
      $tr.append(
        $("<td></td>").text(
          row.managed
            ? wcMayaAdmin.i18n.webhookStatusManaged
            : wcMayaAdmin.i18n.webhookStatusExternal,
        ),
      );
      $tbody.append($tr);
    });
  }

  function fetchWebhookStatus() {
    const $table = $("#wc-maya-webhook-status-table");
    const $spinner = $("#wc-maya-refresh-webhooks-spinner");
    const $btn = $("#wc-maya-refresh-webhooks");

    if (!$table.length) {
      return;
    }

    $spinner.addClass("is-active");
    $btn.prop("disabled", true);

    $.post(wcMayaAdmin.ajaxUrl, {
      action: wcMayaAdmin.actions.refreshWebhooks,
      nonce: wcMayaAdmin.nonce,
    })
      .done(function (response) {
        if (response && response.success && response.data) {
          renderWebhookStatus($table, response.data.webhooks);
          return;
        }
        const message =
          response && response.data && response.data.message
            ? response.data.message
            : wcMayaAdmin.i18n.unexpectedResponse;
        $table
          .find("tbody")
          .empty()
          .append(
            $("<tr></tr>").append(
              $('<td colspan="4" class="wc-maya-error"></td>').text(message),
            ),
          );
      })
      .fail(function (xhr) {
        const message =
          xhr &&
          xhr.responseJSON &&
          xhr.responseJSON.data &&
          xhr.responseJSON.data.message
            ? xhr.responseJSON.data.message
            : xhr.statusText || wcMayaAdmin.i18n.unexpectedResponse;
        $table
          .find("tbody")
          .empty()
          .append(
            $("<tr></tr>").append(
              $('<td colspan="4" class="wc-maya-error"></td>').text(message),
            ),
          );
      })
      .always(function () {
        $spinner.removeClass("is-active");
        $btn.prop("disabled", false);
      });
  }

  function attachWebhookStatusTable() {
    const $btn = $("#wc-maya-refresh-webhooks");
    if (!$btn.length || $btn.data("wcMayaBound")) {
      return;
    }
    $btn.data("wcMayaBound", true);

    $btn.on("click", fetchWebhookStatus);
    fetchWebhookStatus();
  }

  function attachCopyButton() {
    const $btn = $("#wc-maya-copy-webhook-url");

    if (!$btn.length || $btn.data("wcMayaBound")) {
      return;
    }
    $btn.data("wcMayaBound", true);

    $btn.on("click", function () {
      const $target = $($btn.data("target"));
      if (!$target.length) {
        return;
      }

      const value = String($target.val() || "");

      const restoreLabel = function () {
        window.setTimeout(function () {
          $btn.text(wcMayaAdmin.i18n.copy);
        }, 1500);
      };

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(function () {
          $btn.text(wcMayaAdmin.i18n.copied);
          restoreLabel();
        });
        return;
      }

      $target.trigger("select");
      try {
        document.execCommand("copy");
        $btn.text(wcMayaAdmin.i18n.copied);
        restoreLabel();
      } catch (e) {
        /* ignore */
      }
    });
  }

  function attachCaptureFlow() {
    const $trigger = $(".wc-maya-capture-trigger");
    const $panel = $("#wc-maya-capture-panel");

    if (!$panel.length || $panel.data("wcMayaBound")) {
      return;
    }
    $panel.data("wcMayaBound", true);

    if ($trigger.length) {
      $trigger.on("click", function () {
        $panel.attr("hidden", false).find("#wc-maya-capture-amount").trigger("focus");
      });
    } else {
      // No "Capture" trigger button (e.g. all funds already captured) — show
      // the panel directly so the merchant still sees the balances.
      $panel.attr("hidden", false);
    }

    const $submit = $("#wc-maya-capture-submit");
    const $amount = $("#wc-maya-capture-amount");
    const $spinner = $("#wc-maya-capture-spinner");
    const $result = $("#wc-maya-capture-result");

    $submit.on("click", function () {
      const orderId = $panel.data("order-id");
      const amount = parseFloat($amount.val() || "0");

      if (!orderId || !(amount > 0)) {
        $result
          .empty()
          .append($('<p class="wc-maya-error"></p>').text(
            wcMayaAdmin.i18n.unexpectedResponse,
          ));
        return;
      }

      $result.empty().append($("<p></p>").text(wcMayaAdmin.i18n.captureSubmitting));
      $spinner.addClass("is-active");
      $submit.prop("disabled", true);

      $.post(wcMayaAdmin.ajaxUrl, {
        action: wcMayaAdmin.actions.capturePayment,
        nonce: wcMayaAdmin.nonce,
        order_id: orderId,
        capture_amount: amount,
      })
        .done(function (response) {
          if (response && response.success && response.data) {
            $panel
              .find(".wc-maya-amount-authorized")
              .text(Number(response.data.amount_authorized).toFixed(2));
            $panel
              .find(".wc-maya-amount-captured")
              .text(Number(response.data.amount_captured).toFixed(2));
            $panel
              .find(".wc-maya-amount-remaining")
              .text(Number(response.data.amount_remaining).toFixed(2));

            const remaining = Number(response.data.amount_remaining);
            $amount.val(remaining.toFixed(2));
            if (!(remaining > 0.005)) {
              $submit.prop("disabled", true);
            }

            $result
              .empty()
              .append($('<p class="wc-maya-ok"></p>').text(
                wcMayaAdmin.i18n.captureSuccess,
              ));
            return;
          }
          const message =
            response && response.data && response.data.message
              ? response.data.message
              : wcMayaAdmin.i18n.unexpectedResponse;
          $result
            .empty()
            .append($('<p class="wc-maya-error"></p>').text(message));
        })
        .fail(function (xhr) {
          const message =
            xhr &&
            xhr.responseJSON &&
            xhr.responseJSON.data &&
            xhr.responseJSON.data.message
              ? xhr.responseJSON.data.message
              : xhr.statusText || wcMayaAdmin.i18n.unexpectedResponse;
          $result
            .empty()
            .append($('<p class="wc-maya-error"></p>').text(message));
        })
        .always(function () {
          $spinner.removeClass("is-active");
          if (parseFloat($amount.val() || "0") > 0) {
            $submit.prop("disabled", false);
          }
        });
    });
  }

  $(function () {
    attachKeyToggles();
    attachTestConnection();
    attachSimulator();
    attachWebhookStatusTable();
    attachCopyButton();
    attachCaptureFlow();
  });
})(jQuery);
