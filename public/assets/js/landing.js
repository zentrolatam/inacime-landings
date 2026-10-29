(function () {
  "use strict";

  var configNode = document.getElementById("landing-config");
  if (!configNode) {
    return;
  }

  var cfg = JSON.parse(configNode.textContent);
  var params = new URLSearchParams(window.location.search);

  captureCampaign();
  initPixel(cfg.pixelId);
  initGa4(cfg.ga4Id);

  var form = document.getElementById("lead-form");
  var nameInput = document.getElementById("nombre");
  var phoneInput = document.getElementById("telefono");
  var honeypot = document.getElementById("empresa");
  var button = document.getElementById("submit");
  var label = document.getElementById("submit-label");
  var preview = document.getElementById("preview");
  var previewText = document.getElementById("preview-text");
  var fallback = document.getElementById("wa-fallback");
  var careers = Array.prototype.slice.call(document.querySelectorAll(".career"));
  var careerGroup = document.getElementById("careers");
  var touched = { name: false, phone: false };
  var tried = false;
  var loading = false;

  nameInput.addEventListener("input", function () {
    markFormStart();
    render();
  });
  nameInput.addEventListener("blur", function () {
    touched.name = true;
    render();
  });
  phoneInput.addEventListener("input", function () {
    markFormStart();
    var next = phoneInput.value.replace(/\D/g, "").slice(0, 10);
    if (next !== phoneInput.value) {
      phoneInput.value = next;
    }
    render();
  });
  phoneInput.addEventListener("blur", function () {
    touched.phone = true;
    render();
  });
  careers.forEach(function (career) {
    career.addEventListener("click", function () {
      careers.forEach(function (other) {
        var selected = other === career;
        other.classList.toggle("is-selected", selected);
        other.setAttribute("aria-checked", selected ? "true" : "false");
      });
      render();
    });
  });

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    tried = true;
    render();
    if (loading || !isValid()) {
      return;
    }
    submitLead();
  });

  render();

  function captureCampaign() {
    var keys = ["utm_source", "utm_campaign", "utm_content"];
    keys.forEach(function (key) {
      var value = params.get(key);
      if (!value) {
        return;
      }
      try {
        window.sessionStorage.setItem(key, value.slice(0, 200));
      } catch (error) {
        /* sessionStorage puede estar bloqueado */
      }
    });

    var fbclid = params.get("fbclid");
    if (!fbclid || readCookie("_fbc") || fbclid.length > 400 || !/^[A-Za-z0-9._\-]+$/.test(fbclid)) {
      return;
    }
    var cookie = "fb.1." + Date.now() + "." + fbclid;
    var parts = ["_fbc=" + cookie, "Max-Age=7776000", "Path=/", "SameSite=Lax"];
    if (window.location.protocol === "https:") {
      parts.push("Secure");
    }
    var domain = rootDomain();
    if (domain) {
      parts.push("Domain=." + domain);
    }
    document.cookie = parts.join("; ");
  }

  function rootDomain() {
    var host = window.location.hostname;
    if (host === "localhost" || host.endsWith(".localhost") || /^\d{1,3}(\.\d{1,3}){3}$/.test(host)) {
      return "";
    }
    var labels = host.split(".");
    if (labels.length < 2) {
      return "";
    }
    return labels.slice(-2).join(".");
  }

  function readCookie(name) {
    var match = document.cookie.match(new RegExp("(?:^|; )" + name + "=([^;]*)"));
    return match ? decodeURIComponent(match[1]) : "";
  }

  function utm(key) {
    var fromUrl = params.get(key);
    if (fromUrl) {
      return fromUrl.slice(0, 200);
    }
    try {
      return window.sessionStorage.getItem(key) || "";
    } catch (error) {
      return "";
    }
  }

  function initPixel(pixelId) {
    if (!pixelId || window.fbq) {
      return;
    }
    !function (f, b, e, v, n, t, s) {
      if (f.fbq) return;
      n = f.fbq = function () {
        n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
      };
      if (!f._fbq) f._fbq = n;
      n.push = n;
      n.loaded = !0;
      n.version = "2.0";
      n.queue = [];
      t = b.createElement(e);
      t.async = !0;
      t.src = v;
      s = b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t, s);
    }(window, document, "script", "https://connect.facebook.net/en_US/fbevents.js");
    window.fbq("init", pixelId);
    window.fbq("track", "PageView");
  }

  // Google Analytics 4: solo se carga si GA4_MEASUREMENT_ID está configurado.
  // Nunca se envían nombre ni teléfono; solo landing, plantel, programa y folio.
  function initGa4(measurementId) {
    if (!measurementId || window.gtag) {
      return;
    }
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag("js", new Date());
    window.gtag("config", measurementId, { landing: cfg.slug, plantel: cfg.plantel || "" });
    var script = document.createElement("script");
    script.async = true;
    script.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(measurementId);
    document.head.appendChild(script);
  }
  
  function ga4(name, params) {
    if (typeof window.gtag !== "function") {
      return;
    }
    var base = { landing: cfg.slug, plantel: cfg.plantel || "", programa: selectedProgram() || "", transport_type: "beacon" };
    Object.keys(params || {}).forEach(function (key) { base[key] = params[key]; });
    window.gtag("event", name, base);
  }
  
  var formStarted = false;
  function markFormStart() {
    if (formStarted) {
      return;
    }
    formStarted = true;
    ga4("form_start");
  }
  
  function letterCount(value) {
    var found = value.match(/\p{L}/gu);
    return found ? found.length : 0;
  }

  function nameOk() {
    return letterCount(nameInput.value.trim()) >= 2;
  }

  function phoneOk() {
    return phoneInput.value.length === 10;
  }

  function selectedProgram() {
    if (!cfg.selector) {
      return cfg.programa || "";
    }
    var selected = document.querySelector(".career.is-selected");
    return selected ? (selected.getAttribute("data-programa") || "") : "";
  }

  function isValid() {
    return nameOk() && phoneOk() && (!cfg.selector || selectedProgram() !== "");
  }

  function message(folio) {
    return String(cfg.mensaje)
      .replaceAll("{programa}", selectedProgram())
      .replaceAll("{folio}", folio);
  }

  function messageWithoutFolio() {
    return String(cfg.mensaje)
      .replaceAll("{programa}", selectedProgram())
      .replace(/\s*\(\s*folio\s+\{folio\}\s*\)\s*/i, "")
      .replaceAll("{folio}", "")
      .trim();
  }

  function waUrl(text) {
    return "https://wa.me/" + cfg.whatsapp + "?text=" + rawurlencode(text);
  }

  function rawurlencode(value) {
    return encodeURIComponent(value).replace(/[!'()*]/g, function (char) {
      return "%" + char.charCodeAt(0).toString(16).toUpperCase();
    });
  }

  function render() {
    var valid = isValid();
    var showName = (touched.name || tried) && !nameOk();
    var showPhone = (touched.phone || tried) && !phoneOk();
    var showCareer = cfg.selector && selectedProgram() === "" && (touched.name || touched.phone || tried);

    setError("nombre", showName, "Escribe tu nombre (mínimo 2 letras).");
    setError("telefono", showPhone, "Ingresa tu WhatsApp a 10 dígitos.");
    var careerError = document.getElementById("carrera-error");
    if (careerError) {
      careerError.hidden = !showCareer;
      if (careerGroup) {
        careerGroup.classList.toggle("is-invalid", showCareer);
      }
    }

    button.disabled = loading || !valid;
    button.classList.toggle("is-loading", loading);
    label.textContent = loading ? "Abriendo WhatsApp…" : "Continuar a WhatsApp";
    preview.hidden = !valid;
    if (valid) {
      previewText.textContent = message("····");
    }
  }

  function setError(field, show, text) {
    var input = document.getElementById(field);
    var error = document.getElementById(field + "-error");
    var wrap = input.closest(".field");
    error.hidden = !show;
    error.textContent = show ? text : "";
    wrap.classList.toggle("is-invalid", show);
    input.setAttribute("aria-invalid", show ? "true" : "false");
  }

  function eventId() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    var bytes = new Uint8Array(16);
    if (window.crypto && window.crypto.getRandomValues) {
      window.crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < 16; i++) {
        bytes[i] = Math.floor(Math.random() * 256);
      }
    }
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    var hex = Array.prototype.map.call(bytes, function (byte) {
      return byte.toString(16).padStart(2, "0");
    }).join("");
    return hex.slice(0, 8) + "-" + hex.slice(8, 12) + "-" + hex.slice(12, 16) + "-" + hex.slice(16, 20) + "-" + hex.slice(20);
  }

  function isWhatsApp(url) {
    try {
      var parsed = new URL(url);
      return parsed.protocol === "https:" && parsed.hostname === "wa.me";
    } catch (error) {
      return false;
    }
  }

  // WhatsApp solo se abre aquí, después de que la persona envía el formulario.
  function openWhatsApp(url) {
    fallback.href = url;
    fallback.hidden = false;
    ga4("whatsapp_open", { con_folio: url.indexOf("folio") !== -1 });
    window.location.assign(url);
  }

  function submitLead() {
    loading = true;
    render();
    var id = eventId();
    var payload = {
      slug: cfg.slug,
      nombre: nameInput.value.trim(),
      telefono: phoneInput.value,
      programa: selectedProgram(),
      utm_source: utm("utm_source"),
      utm_campaign: utm("utm_campaign"),
      utm_content: utm("utm_content"),
      fbc: readCookie("_fbc"),
      fbp: readCookie("_fbp"),
      event_id: id,
      page_url: window.location.href.split("#")[0],
      honeypot: honeypot.value
    };
    var controller = new AbortController();
    var timer = window.setTimeout(function () {
      controller.abort();
    }, 4000);

    fetch("/api/lead.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify(payload),
      signal: controller.signal,
      credentials: "same-origin"
    }).then(function (response) {
      return response.json().then(function (data) {
        return { ok: response.ok, data: data };
      });
    }).then(function (result) {
      if (!result.ok || !result.data || result.data.ok !== true || !isWhatsApp(result.data.whatsapp_url)) {
        throw new Error("respuesta");
      }
      if (typeof window.fbq === "function") {
        window.fbq("track", "Lead", {}, { eventID: id });
      }
      ga4("generate_lead", { folio: String(result.data.folio || "") });
      openWhatsApp(result.data.whatsapp_url);
    }).catch(function (error) {
      console.error("No se pudo registrar el lead", error);
      openWhatsApp(waUrl(messageWithoutFolio()));
    }).finally(function () {
      window.clearTimeout(timer);
    });
  }
})();
