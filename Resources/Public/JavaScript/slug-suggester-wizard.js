/**
 * AI Editorial Helper — slug-suggester wizard.
 *
 * Mounted as an ES module by SuggestSlugWizard. Listens for clicks on
 * `.ai-editorial-helper-suggest-slug` buttons inside the page-edit form,
 * sends the current title (from the form, not the saved record) to the AJAX
 * endpoint, and patches the returned slug back into the slug input.
 */
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";
import { extractAjaxErrorMessage } from "@kairos/ai-editorial-helper/ajax-error.js";

const SELECTOR = ".ai-editorial-helper-suggest-slug";
const BUSY_CLASS = "ai-editorial-helper-busy";

function findInputForField(form, fieldName) {
  if (!form) {
    return null;
  }
  const escaped = fieldName.replace(/[\\"]/g, "\\$&");
  return form.querySelector(`[data-formengine-input-name$="[${escaped}]"]`);
}

function readTitleFromForm(form) {
  const input = findInputForField(form, "title");
  if (input && input.value) {
    return input.value.trim();
  }
  // nav_title is a reasonable fallback when title is empty.
  const nav = findInputForField(form, "nav_title");
  return nav && nav.value ? nav.value.trim() : "";
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

function setSlug(form, fieldName, value) {
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
  const fieldName = button.dataset.field || "slug";
  const form = button.closest("form");
  const title = readTitleFromForm(form);

  if (!title && !pageUid) {
    Notification.error(
      "AI Editorial Helper",
      "Type a page title first — there's nothing to derive a slug from yet.",
    );
    return;
  }

  button.classList.add(BUSY_CLASS);
  button.disabled = true;
  const originalText = button.textContent;
  button.textContent = "Suggesting…";

  try {
    const args = {};
    if (pageUid > 0) {
      args.pageUid = pageUid;
    }
    if (title) {
      args.title = title;
    }
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_editorial_helper_slug)
      .withQueryArguments(args)
      .get();
    const payload = await response.resolve();

    if (!payload || payload.success !== true) {
      throw new Error(payload && payload.error ? payload.error : "Unknown error");
    }

    if (setSlug(form, fieldName, payload.slug)) {
      Notification.success("AI Editorial Helper", "Slug suggestion applied. Review and save.");
    } else {
      Notification.warning(
        "AI Editorial Helper",
        `Generated slug "${payload.slug}" but couldn't find the slug field.`,
      );
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
