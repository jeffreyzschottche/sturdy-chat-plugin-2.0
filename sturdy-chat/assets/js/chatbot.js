(function () {
  "use strict";

  /**
   * Retrieves the REST URL based on the provided element's attributes or fallback values.
   *
   * @param {HTMLElement} el - The HTML element from which to retrieve the URL attributes.
   * @return {string} The resolved REST URL as a string. If no URL is found, an empty string is returned.
   */
  function getRestUrl(el) {
    const fromData = el.getAttribute("data-rest-url");
    const fromLocalized =
      window.STURDYCHAT && window.STURDYCHAT.restUrl
        ? window.STURDYCHAT.restUrl
        : null;
    const fallback =
      el.getAttribute("data-rest-fallback") ||
      (window.STURDYCHAT && window.STURDYCHAT.restUrlFallback);
    return (fromData || fromLocalized || fallback || "").toString();
  }

  /**
   * Initializes and mounts a chat interface into a specified root DOM element.
   *
   * @param {HTMLElement} root - The root DOM element where the chat interface will be rendered.
   * @return {void} - Does not return a value.
   */
  function mount(root) {
    const restUrl = getRestUrl(root);
    const title =
      (window.STURDYCHAT && window.STURDYCHAT.title) || "Zoeken";
    const placeholder =
      (window.STURDYCHAT && window.STURDYCHAT.placeholder) ||
      "Zoeken";
    const btnText = (window.STURDYCHAT && window.STURDYCHAT.button) || "<span class=\"innericon\"'><svg xmlns=\"http://www.w3.org/2000/svg\" width=\"22\" fill=\"white\" height=\"22\" viewBox=\"0 0 512 512\"><path d=\"M460.475 408.443L351.4 299.37c15.95-25.137 25.2-54.923 25.2-86.833C376.6 122.914 303.687 50 214.062 50 124.44 50 51.525 122.914 51.525 212.537s72.914 162.537 162.537 162.537c30.326 0 58.733-8.356 83.055-22.876L406.917 462l53.558-53.557zM112.117 212.537c0-56.213 45.732-101.946 101.945-101.946 56.213 0 101.947 45.734 101.947 101.947S270.275 314.482 214.06 314.482c-56.213 0-101.945-45.732-101.945-101.945z\"></path></svg></span>";

    root.innerHTML = [
      `<h3>${title}</h3>`,
      `<div class="sturdychat-messages" aria-live="polite" aria-busy="false"></div>`,
      `<form><input type="text" name="q" placeholder="${placeholder}" autocomplete="off"/><button type="submit">${btnText}</button></form>`,
    ].join("");



    const messages = root.querySelector(".sturdychat-messages");
    const form = root.querySelector("form");
    const input = form.querySelector('input[name="q"]');

    // Open bij klik ergens in de widget
    root.addEventListener("click", function () {
      if (!root.classList.contains("active")) {
        root.classList.add("active");
      }
    });

    document.addEventListener("click", function (e) {
      const inWidget = root.contains(e.target);
      const inMessages = e.target.closest(".sturdychat-messages");
      const inForm = e.target.closest("form");

      // Als open en je klikt óf buiten de widget, óf binnen de widget maar niet in messages of form -> sluit
      if (root.classList.contains("active") && (!inWidget || (!inMessages && !inForm))) {
        root.classList.remove("active");
      }
    });

// Open zodra je in het inputveld klikt of focust
    input.addEventListener("focus", function () {
      root.classList.add("active");
    });
    form.addEventListener("click", function () {
      root.classList.add("active");
    });

// (Optioneel) sluit met Escape
    root.addEventListener("keydown", function (e) {
      if (e.key === "Escape") root.classList.remove("active");
    });


    if (messages.childElementCount > 0) {
      root.classList.add("active");
    }


    function setBusy(busy) {
      messages.setAttribute("aria-busy", busy ? "true" : "false");
    }

    /**
     * Appends a message to the chat interface.
     *
     * @param {string} text - The message content to be displayed.
     * @param {string} role - The role of the sender, either "user" or "bot".
     * @return {void} This function does not return a value.
     */
    function pushMsg(text, role) {
      const div = document.createElement("div");
      div.className = "sturdychat-msg " + (role === "user" ? "user" : "bot");
      div.textContent = text;
      messages.appendChild(div);
      messages.scrollTop = messages.scrollHeight;
    }

    function pushHtml(html) {
      const div = document.createElement("div");
      div.className = "sturdychat-msg bot";
      div.innerHTML = html;
      messages.appendChild(div);
      messages.scrollTop = messages.scrollHeight;
    }

    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      const q = (input.value || "").trim();
      if (!q) return;
      pushMsg(q, "user");
      input.value = "";

      const url = restUrl || getRestUrl(root);
      setBusy(true);
      try {
        const res = await fetch(url, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ question: q }),
        });
        // const json = await res.json();
        // if (!res.ok) {
        //   pushMsg(json && json.message ? json.message : "Server error", "bot");
        //   return;
        // }
        // pushMsg(json.answer || "(geen antwoord)", "bot");
        const json = await res.json();
        if (!res.ok) {
          pushMsg(json && json.message ? json.message : "Server error", "bot");
          return;
        }

// Antwoord + bronnen in dezelfde bot-bubble
        const ans = (json.answer || "(geen antwoord)") + "";
        const safeAns = ans
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/\n/g, "<br>");

        let html = `<div class="sturdychat-text">${safeAns}</div>`;

        if (Array.isArray(json.sources) && json.sources.length) {
          const items = json.sources.map((s) => {
            let title = (s.title && s.title.trim()) ? s.title.trim() : s.url;
            if (title.length > 25) title = title.substring(0, 25) + "…"; // max 25 chars
            return `<li><a href="${s.url}" target="_blank" rel="noopener">${title}</a></li>`;
          }).join("");


          html += `
    <div class="sturdychat-sources">
      <strong>Bronnen</strong>
      <ol>${items}</ol>
    </div>
  `;
        }

        pushHtml(html);


      } catch (err) {
        pushMsg(err && err.message ? err.message : "Network error", "bot");
      } finally {
        setBusy(false);
      }
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".sturdychat-chatbot").forEach(mount);
  });
})();
