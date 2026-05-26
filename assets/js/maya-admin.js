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

  $(function () {
    attachKeyToggles();
    attachTestConnection();
    attachCopyButton();
  });
})(jQuery);
