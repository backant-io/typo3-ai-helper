/**
 * AI Editorial Helper — quality-checker wizard.
 *
 * On click, runs all rule-based + LLM-based checks server-side and renders
 * the resulting flag list inline. Severity is shown via colored badges.
 * No form fields are mutated — this is a read-only audit.
 */
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";
import { extractAjaxErrorMessage } from "@kairos/ai-editorial-helper/ajax-error.js";

const SELECTOR = ".ai-editorial-helper-check-quality";
const BUSY_CLASS = "ai-editorial-helper-busy";

const SEVERITY_BADGE = {
  error: { label: "ERROR", color: "#d32f2f" },
  warning: { label: "WARN", color: "#f57c00" },
  info: { label: "INFO", color: "#1976d2" },
};

function clearChildren(node) {
  while (node.firstChild) {
    node.removeChild(node.firstChild);
  }
}

function appendStatus(panel, message, className = "text-muted") {
  const p = document.createElement("p");
  p.className = className;
  p.textContent = message;
  panel.appendChild(p);
}

function renderFlag(panel, flag) {
  const sev = SEVERITY_BADGE[flag.severity] || SEVERITY_BADGE.info;

  const row = document.createElement("div");
  row.className = "ai-editorial-helper-quality-flag";
  row.style.padding = "6px 0";
  row.style.borderBottom = "1px solid rgba(0,0,0,0.08)";

  const badge = document.createElement("span");
  badge.textContent = sev.label;
  badge.style.display = "inline-block";
  badge.style.padding = "2px 6px";
  badge.style.marginRight = "8px";
  badge.style.fontSize = "0.7rem";
  badge.style.fontWeight = "600";
  badge.style.color = "#fff";
  badge.style.backgroundColor = sev.color;
  badge.style.borderRadius = "3px";
  row.appendChild(badge);

  const kindLabel = document.createElement("strong");
  kindLabel.textContent = flag.kind + ": ";
  kindLabel.style.marginRight = "4px";
  row.appendChild(kindLabel);

  const message = document.createElement("span");
  message.textContent = flag.message;
  row.appendChild(message);

  if (flag.location) {
    const loc = document.createElement("div");
    loc.style.marginTop = "4px";
    loc.style.fontSize = "0.85rem";
    loc.style.color = "#555";
    loc.style.fontStyle = "italic";
    loc.textContent = `“${flag.location}”`;
    row.appendChild(loc);
  }

  panel.appendChild(row);
}

function renderSummary(panel, counts, includesLlm) {
  const summary = document.createElement("p");
  summary.style.marginBottom = "8px";
  summary.style.fontWeight = "600";
  const parts = [];
  if (counts.error) parts.push(`${counts.error} error${counts.error === 1 ? "" : "s"}`);
  if (counts.warning) parts.push(`${counts.warning} warning${counts.warning === 1 ? "" : "s"}`);
  if (counts.info) parts.push(`${counts.info} note${counts.info === 1 ? "" : "s"}`);
  if (parts.length === 0) {
    summary.textContent = includesLlm ? "No issues found." : "No rule-based issues found.";
    summary.style.color = "#388e3c";
  } else {
    summary.textContent = parts.join(", ") + ".";
  }
  panel.appendChild(summary);
}

function renderResults(panel, payload) {
  clearChildren(panel);
  if (!payload || !Array.isArray(payload.flags)) {
    appendStatus(panel, "Unexpected response shape.");
    return;
  }
  renderSummary(panel, payload.counts || { error: 0, warning: 0, info: 0 }, !!payload.includesLlm);
  if (payload.flags.length === 0) {
    return;
  }
  const list = document.createElement("div");
  list.className = "ai-editorial-helper-quality-flags";
  panel.appendChild(list);
  payload.flags.forEach((flag) => renderFlag(list, flag));
}

async function handleClick(event) {
  const button = event.currentTarget;
  if (button.classList.contains(BUSY_CLASS)) {
    return;
  }
  const pageUid = parseInt(button.dataset.pageUid || "0", 10);
  if (!pageUid) {
    Notification.error("AI Editorial Helper", "No page UID — save the page first.");
    return;
  }

  const wizard = button.closest(".ai-editorial-helper-quality-wizard");
  const panel = wizard ? wizard.querySelector(".ai-editorial-helper-quality-results") : null;

  button.classList.add(BUSY_CLASS);
  button.disabled = true;
  const originalText = button.textContent;
  button.textContent = "Checking…";
  if (panel) {
    clearChildren(panel);
    appendStatus(panel, "Running checks…");
  }

  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_editorial_helper_quality)
      .withQueryArguments({ pageUid })
      .get();
    const payload = await response.resolve();

    if (!payload || payload.success !== true) {
      throw new Error(payload && payload.error ? payload.error : "Unknown error");
    }

    if (panel) {
      renderResults(panel, payload);
    }
  } catch (err) {
    if (panel) {
      clearChildren(panel);
    }
    const message = await extractAjaxErrorMessage(err);
    Notification.error("AI Editorial Helper", message);
  } finally {
    button.classList.remove(BUSY_CLASS);
    button.disabled = false;
    button.textContent = originalText;
  }
}

function bind(root) {
  root.querySelectorAll(SELECTOR).forEach((button) => {
    if (button.dataset.aiHelperBound === "1") {
      return;
    }
    button.dataset.aiHelperBound = "1";
    button.addEventListener("click", handleClick);
  });
}

bind(document);

const observer = new MutationObserver((mutations) => {
  for (const mutation of mutations) {
    mutation.addedNodes.forEach((node) => {
      if (node instanceof HTMLElement) {
        bind(node);
      }
    });
  }
});
observer.observe(document.body, { childList: true, subtree: true });
