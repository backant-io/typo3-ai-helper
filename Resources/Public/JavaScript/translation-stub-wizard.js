/**
 * AI Editorial Helper — translation-stub wizard.
 *
 * Mounted as an ES module by GenerateTranslationStubWizard. On click, calls
 * the AJAX endpoint to translate the source-language tt_content row into the
 * current row's language. Patches the result into the bodytext field.
 *
 * Notes:
 *   - The bodytext editor is typically CKEditor or RTE — we set the raw
 *     hidden FormEngine input value, then dispatch change/blur. The RTE
 *     re-binds on the change event in v13.
 *   - Stripped HTML and unicode are preserved by the server-side DOMDocument
 *     pipeline; the client just shoves the string in and lets FormEngine
 *     re-render.
 */
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";
import { extractAjaxErrorMessage } from "@kairos/ai-editorial-helper/ajax-error.js";

const SELECTOR = ".ai-editorial-helper-translate-stub";
const BUSY_CLASS = "ai-editorial-helper-busy";

function findBodytextInput(form) {
  if (!form) {
    return null;
  }
  // Match either the visible (RTE-managed) textarea or the raw hidden input.
  return (
    form.querySelector('textarea[data-formengine-input-name$="[bodytext]"]')
    || form.querySelector('input[type="hidden"][data-formengine-input-name$="[bodytext]"]')
    || form.querySelector('[data-formengine-input-name$="[bodytext]"]')
  );
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
  const hidden = form.querySelector(`input[type="hidden"][name="${CSS.escape(name)}"]`);
  if (hidden && hidden !== input) {
    hidden.value = input.value;
  }
  input.dispatchEvent(new Event("change", { bubbles: true }));
  input.dispatchEvent(new Event("blur", { bubbles: true }));
}

async function handleClick(event) {
  const button = event.currentTarget;
  if (button.classList.contains(BUSY_CLASS)) {
    return;
  }
  const contentUid = parseInt(button.dataset.contentUid || "0", 10);
  if (!contentUid) {
    Notification.error("AI Editorial Helper", "No content UID — save the localisation first.");
    return;
  }

  const form = button.closest("form");
  button.classList.add(BUSY_CLASS);
  button.disabled = true;
  const originalText = button.textContent;
  button.textContent = "Translating…";

  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_editorial_helper_translate)
      .withQueryArguments({ contentUid })
      .get();
    const payload = await response.resolve();

    if (!payload || payload.success !== true) {
      throw new Error(payload && payload.error ? payload.error : "Unknown error");
    }

    const input = findBodytextInput(form);
    if (!input) {
      Notification.warning(
        "AI Editorial Helper",
        "Translated content but couldn't find the bodytext field.",
      );
      return;
    }

    input.value = payload.translation;
    syncHiddenCounterpart(input);
    Notification.success(
      "AI Editorial Helper",
      `Translation stub generated (${payload.sourceLang} → ${payload.targetLang}). Review and refine before saving.`,
    );
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
