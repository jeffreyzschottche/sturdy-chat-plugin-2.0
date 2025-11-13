(function () {
  "use strict";

  const SPEED = 18;

  function shouldReduceMotion() {
    if (typeof window === "undefined" || !window.matchMedia) {
      return false;
    }
    try {
      return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    } catch (e) {
      return false;
    }
  }

  function typeHtml(node, html) {
    let idx = 0;
    let buffer = "";

    function step() {
      if (idx >= html.length) {
        node.innerHTML = html;
        node.classList.remove("is-typing");
        return;
      }

      const char = html[idx];
      if (char === "<") {
        const close = html.indexOf(">", idx);
        if (close === -1) {
          node.innerHTML = html;
          node.classList.remove("is-typing");
          return;
        }
        buffer += html.slice(idx, close + 1);
        idx = close + 1;
        node.innerHTML = buffer;
        step();
        return;
      }

      buffer += char;
      idx += 1;
      node.innerHTML = buffer;
      setTimeout(step, SPEED);
    }

    node.classList.add("is-typing");
    step();
  }

  function hydrateResult(result) {
    const textNode = result.querySelector(".sturdychat-result__answer-text");
    const template = result.querySelector("template.sturdychat-answer-template");
    if (!textNode || !template) {
      return;
    }

    const html = template.innerHTML.trim();
    if (!html) {
      return;
    }

    if (shouldReduceMotion()) {
      textNode.innerHTML = html;
      return;
    }

    typeHtml(textNode, html);
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".sturdychat-result").forEach(hydrateResult);
  });
})();
