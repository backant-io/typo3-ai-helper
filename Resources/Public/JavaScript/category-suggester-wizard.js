/**
 * AI Editorial Helper — category-suggester wizard.
 *
 * Mounted as an ES module by SuggestCategoriesWizard. On click, fetches
 * suggestions, renders them as confidence-tagged chips, and on chip click,
 * patches the corresponding category UID into the categories field's hidden
 * input (which is what FormEngine reads on save).
 */
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";

const SELECTOR = ".ai-editorial-helper-suggest-categories";
const BUSY_CLASS = "ai-editorial-helper-busy";

function findHiddenCategoryInput(form, fieldName) {
  if (!form) {
    return null;
  }
  // FormEngine renders the categories field as a tree widget plus a hidden
  // input that holds the comma-separated UIDs. Look for the hidden one by
  // its FormEngine input-name suffix.
  const escaped = fieldName.replace(/[\\"]/g, "\\$&");
  return form.querySelector(`input[type="hidden"][data-formengine-input-name$="[${escaped}]"]`)
    || form.querySelector(`input[type="hidden"][name$="[${escaped}]"]`);
}

function currentSelectedUids(input) {
  if (!input || !input.value) {
    return new Set();
  }
  return new Set(
    input.value
      .split(",")
      .map((s) => parseInt(s.trim(), 10))
      .filter((n) => Number.isInteger(n) && n > 0),
  );
}

function applyCategory(form, fieldName, uid) {
  const hidden = findHiddenCategoryInput(form, fieldName);
  if (!hidden) {
    return false;
  }
  const selected = currentSelectedUids(hidden);
  if (selected.has(uid)) {
    return true; // already selected — no-op success
  }
  selected.add(uid);
  hidden.value = Array.from(selected).join(",");
  hidden.dispatchEvent(new Event("change", { bubbles: true }));
  return true;
}

function clearChildren(node) {
  while (node.firstChild) {
    node.removeChild(node.firstChild);
  }
}

function appendStatusMessage(panel, message, className = "text-muted") {
  const p = document.createElement("p");
  p.className = className;
  p.textContent = message;
  panel.appendChild(p);
}

function renderSuggestions(panel, suggestions, form, fieldName) {
  clearChildren(panel);
  if (!suggestions.length) {
    appendStatusMessage(panel, "No matching categories found.");
    return;
  }

  suggestions.forEach((s) => {
    const chip = document.createElement("button");
    chip.type = "button";
    chip.className = "btn btn-sm btn-default ai-editorial-helper-category-chip";
    chip.style.marginRight = "4px";
    chip.style.marginTop = "4px";
    chip.dataset.uid = String(s.uid);
    const pct = Math.round((s.confidence || 0) * 100);
    // textContent treats input as literal — safe even if title contains markup-like chars.
    chip.textContent = `+ ${s.title} (${pct}%)`;
    chip.addEventListener("click", () => {
      if (applyCategory(form, fieldName, s.uid)) {
        chip.disabled = true;
        chip.textContent = `✓ ${s.title} (${pct}%)`;
        Notification.success(
          "AI Editorial Helper",
          `Added category "${s.title}". Save the page to persist.`,
        );
      } else {
        Notification.warning(
          "AI Editorial Helper",
          `Couldn't find the categories field — manually add "${s.title}" (uid ${s.uid}).`,
        );
      }
    });
    panel.appendChild(chip);
  });
}

async function handleClick(event) {
  const button = event.currentTarget;
  if (button.classList.contains(BUSY_CLASS)) {
    return;
  }
  const pageUid = parseInt(button.dataset.pageUid || "0", 10);
  const fieldName = button.dataset.field || "categories";
  if (!pageUid) {
    Notification.error("AI Editorial Helper", "No page UID — save the page first.");
    return;
  }

  const wizard = button.closest(".ai-editorial-helper-categories-wizard");
  const panel = wizard ? wizard.querySelector(".ai-editorial-helper-suggestions") : null;
  const form = button.closest("form");

  button.classList.add(BUSY_CLASS);
  button.disabled = true;
  const originalText = button.textContent;
  button.textContent = "Suggesting…";
  if (panel) {
    clearChildren(panel);
    appendStatusMessage(panel, "Asking the model…");
  }

  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_editorial_helper_categories)
      .withQueryArguments({ pageUid })
      .get();
    const payload = await response.resolve();

    if (!payload || payload.success !== true) {
      throw new Error(payload && payload.error ? payload.error : "Unknown error");
    }

    if (panel) {
      renderSuggestions(panel, payload.suggestions || [], form, fieldName);
    }
  } catch (err) {
    if (panel) {
      clearChildren(panel);
    }
    const message = err && err.message ? err.message : String(err);
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
