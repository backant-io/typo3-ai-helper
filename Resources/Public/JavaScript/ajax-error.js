/**
 * Shared error-message extractor for AI Editorial Helper wizards.
 *
 * Background (issue #19): TYPO3's AjaxRequest rejects with an AjaxResponse
 * object on non-2xx, NOT a JS Error. Naively casting that to String produces
 * "[object Object]". We need to dig out the JSON body the controller sent
 * (`{ success: false, error: "human-readable message" }`) and surface that.
 *
 * @param {unknown} err  Whatever the catch block received.
 * @returns {Promise<string>}  A user-presentable error message.
 */
export async function extractAjaxErrorMessage(err) {
  if (err && typeof err === "object" && err.response && typeof err.response.resolve === "function") {
    try {
      const body = await err.response.resolve();
      if (body && typeof body === "object" && typeof body.error === "string" && body.error.length > 0) {
        return body.error;
      }
    } catch (_inner) {
      // fall through to status text
    }
    if (err.response.statusText) {
      return err.response.statusText;
    }
    if (typeof err.response.status === "number") {
      return `HTTP ${err.response.status}`;
    }
  }
  if (err && typeof err === "object" && typeof err.message === "string" && err.message.length > 0) {
    return err.message;
  }
  return String(err ?? "Unknown error");
}
