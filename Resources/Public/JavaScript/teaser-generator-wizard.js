/**
 * AI Editorial Helper — teaser-generator wizard.
 *
 * Mounted as an ES module by GenerateTeaserWizard. Listens for clicks on
 * `.ai-editorial-helper-generate-teaser` buttons inside the page-edit form,
 * calls the AJAX endpoint, and patches the result into the abstract field.
 */
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";

const SELECTOR = ".ai-editorial-helper-generate-teaser";
const BUSY_CLASS = "ai-editorial-helper-busy";

function findInputForField(form, fieldName) {
  if (!form) {
    return null;
  }
  const escaped = fieldName.replace(/[\\"]/g, "\\$&");
  // abstract is a textarea — match both input and textarea selectors.
  return form.querySelector(`[data-formengine-input-name$="[${escaped}]"]`);
}

function syncHiddenCounterpart(input) {
  const name = input.getAttribute("data-formengine-input-name");
  if (!name) {
    return;
  }
  const form = input.closest("form");
  if (!form) {
    return;
  }
  const hidden = form.querySelector(`[type="hidden"][name="${CSS.escape(name)}"]`);
  if (hidden && hidden !== input) {
    hidden.value = input.value;
  }
  input.dispatchEvent(new Event("change", { bubbles: true }));
  input.dispatchEvent(new Event("blur", { bubbles: true }));
}

function setFieldValue(form, fieldName, value) {
  const input = findInputForField(form, fieldName);
  if (!input) {
    return false;
  }
  input.value = value;
  syncHiddenCounterpart(input);
  return true;
}

async function handleClick(event) {
  const button = event.currentTarget;
  if (button.classList.contains(BUSY_CLASS)) {
    return;
  }
  const pageUid = parseInt(button.dataset.pageUid || "0", 10);
  const fieldName = button.dataset.field || "abstract";
  if (!pageUid) {
    Notification.error("AI Editorial Helper", "No page UID — save the page first.");
    return;
  }

  const form = button.closest("form");
  button.classList.add(BUSY_CLASS);
  button.disabled = true;
  const originalText = button.textContent;
  button.textContent = "Generating…";

  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_editorial_helper_teaser)
      .withQueryArguments({ pageUid })
      .get();
    const payload = await response.resolve();

    if (!payload || payload.success !== true) {
      throw new Error(payload && payload.error ? payload.error : "Unknown error");
    }

    if (setFieldValue(form, fieldName, payload.teaser)) {
      Notification.success("AI Editorial Helper", "Teaser generated. Review and save.");
    } else {
      Notification.warning(
        "AI Editorial Helper",
        `Generated teaser but couldn't find the ${fieldName} field on this form.`,
      );
    }
  } catch (err) {
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
