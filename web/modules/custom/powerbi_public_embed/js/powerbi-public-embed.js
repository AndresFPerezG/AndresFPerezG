(function (Drupal, once) {
  "use strict";

  function renderStatus(container, text, isError) {
    container.innerHTML = "";
    const message = document.createElement("div");
    message.className = isError
      ? "messages messages--error powerbi-public-embed__status"
      : "messages messages--status powerbi-public-embed__status";
    message.textContent = text;
    container.appendChild(message);
  }

  function renderMock(container, payload) {
    container.innerHTML = "";
    const wrapper = document.createElement("div");
    wrapper.className = "messages messages--warning powerbi-public-embed__mock";
    wrapper.innerHTML =
      "<strong>Modo demo activo.</strong> " +
      "El backend devolvió un token simulado. " +
      "Configura credenciales reales y desactiva mock_mode para embeber Power BI.";

    const details = document.createElement("pre");
    details.style.overflow = "auto";
    details.style.maxHeight = "240px";
    details.textContent = JSON.stringify(payload, null, 2);

    container.appendChild(wrapper);
    container.appendChild(details);
  }

  Drupal.behaviors.powerBiPublicEmbed = {
    attach(context) {
      once("powerbi-public-embed", ".powerbi-public-embed", context).forEach(
        (container) => {
          const endpoint = container.getAttribute("data-endpoint");
          const rawHeight = parseInt(
            container.getAttribute("data-height") || "640",
            10
          );
          const height = Number.isFinite(rawHeight) ? rawHeight : 640;
          container.style.width = "100%";
          container.style.height = `${Math.max(320, height)}px`;
          container.style.minHeight = "320px";

          if (!endpoint) {
            renderStatus(container, Drupal.t("Falta el endpoint de embed."), true);
            return;
          }

          renderStatus(container, Drupal.t("Cargando reporte..."), false);

          fetch(endpoint, {
            method: "GET",
            credentials: "same-origin",
            headers: { Accept: "application/json" },
          })
            .then((response) => {
              if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
              }
              return response.json();
            })
            .then((payload) => {
              if (payload.isMock) {
                renderMock(container, payload);
                return;
              }

              if (!window.powerbi || !window["powerbi-client"]) {
                throw new Error("powerbi-client no está disponible.");
              }

              const models = window["powerbi-client"].models;
              const embedConfig = {
                type: "report",
                id: payload.reportId,
                embedUrl: payload.embedUrl,
                accessToken: payload.accessToken,
                tokenType: models.TokenType.Embed,
                settings: payload.settings || {},
              };

              container.innerHTML = "";
              window.powerbi.reset(container);
              window.powerbi.embed(container, embedConfig);
            })
            .catch((error) => {
              console.error("Power BI embed error", error);
              renderStatus(
                container,
                Drupal.t("No fue posible cargar el reporte en este momento."),
                true
              );
            });
        }
      );
    },
  };
})(Drupal, once);
