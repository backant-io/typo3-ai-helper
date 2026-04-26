/**
 * AI Editorial Helper — meta-description wizard.
 *
 * Mounted as an ES module by GenerateMetaDescriptionWizard. Listens for clicks
 * on `.ai-editorial-helper-generate` buttons inside the page-edit form, calls
 * the AJAX endpoint, and patches the result back into the description and
 * seo_title input fields.
 */
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";
import { extractAjaxErrorMessage } from "@kairos/ai-editorial-helper/ajax-error.js";

const SELECTOR = ".ai-editorial-helper-generate";
const BUSY_CLASS = "ai-editorial-helper-busy";

function findInputForField(form, fieldName) {
  if (!form) {
    return null;
  }
  const escaped = fieldName.replace(/[\\"]/g, "\\$&");
  return form.querySelector(`[data-formengine-input-name$="[${escaped}]"]`);
}

function syncHiddenCounterpart(input) {
  // FormEngine renders a visible <input> plus a hidden counterpart with the
  // raw `data[...]` name. Updating the visible one needs the hidden one to
  // mirror the value or the next save will lose the change.
  const name = input.getAttribute("data-formengine-input-name");
  if (!name) {
    return;
  }
  const form = input.closest("form");
  if (!form) {
    return;
  }
  const hidden = form.querySelector(`input[type="hidden"][name="${CSS.escape(name)}"]`);
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
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_editorial_helper_meta)
      .withQueryArguments({ pageUid })
      .get();
    const payload = await response.resolve();

    if (!payload || payload.success !== true) {
      throw new Error(payload && payload.error ? payload.error : "Unknown error");
    }

    const filledMeta = setFieldValue(form, "description", payload.metaDescription);
    const filledTitle = setFieldValue(form, "seo_title", payload.seoTitle);

    if (!filledMeta && !filledTitle) {
      Notification.warning(
        "AI Editorial Helper",
        "Generated content but couldn't find description/seo_title fields on this form.",
      );
    } else {
      Notification.success("AI Editorial Helper", "Meta description and SEO title generated. Review and save.");
    }
  } catch (err) {
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

// FormEngine re-renders fields on tab switch / inline expand — re-bind on DOM mutation.
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
