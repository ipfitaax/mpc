/**
 * Posts a form to one of the api/ endpoints and reports what actually happened.
 *
 * WHY THIS EXISTS
 * The pages shipped with handlers like
 *
 *     <form onsubmit="event.preventDefault(); …show the thank-you…">
 *
 * which told the student "Thank you for contacting MPC" and sent nothing to
 * anyone. api/enquiry.php was written to fix exactly that, and then a redesign
 * replaced the page and reintroduced it. So the client half of the rule lives
 * here, in one file, rather than being retyped into every form:
 *
 *   NEVER SHOW A SUCCESS MESSAGE THAT THE SERVER DID NOT CONFIRM.
 *
 * The endpoints answer {"ok":true} only after the enquiry is on disk. Anything
 * else — a validation error, a 500, a dead network, a response that is not even
 * JSON — is a failure, and the student is told so and given a phone number.
 * A student who thinks they have applied and has not is worse off than one who
 * knows to call.
 *
 * Shared by index.html and mpc-register.html. Two copies of this logic is how
 * one of them quietly starts lying again.
 */

/** Phone number to fall back to when the server cannot be reached at all. */
window.MPC_CONTACT_PHONE = '+252 770 51 90 98';

/**
 * @param {HTMLFormElement} form  the form to read values from
 * @param {string} url            endpoint, e.g. './api/enquiry.php'
 * @returns {Promise<{ok: boolean, error?: string}>}  never rejects
 */
window.mpcPostForm = function (form, url) {
  // URL-encoded rather than multipart: these are short text fields, there are
  // no file uploads, and $_POST reads it without any special handling.
  var body = new URLSearchParams(new FormData(form));

  return fetch(url, {
    method: 'POST',
    body: body,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }
  })
    .then(function (response) {
      // A PHP fatal error or an HTML error page is not JSON. Read as text
      // first so a parse failure becomes a clear message instead of an
      // unhandled exception that leaves the button spinning forever.
      return response.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { /* handled below */ }

        if (data && data.ok === true) return { ok: true };

        if (data && data.error) return { ok: false, error: data.error };

        return {
          ok: false,
          error: 'Sorry — something went wrong on our side. Please call or WhatsApp '
            + window.MPC_CONTACT_PHONE + '.'
        };
      });
    })
    .catch(function () {
      // Network failure, offline, DNS, blocked request. The submission never
      // reached the server, so nothing was saved.
      return {
        ok: false,
        error: 'We could not reach the server. Check your connection, or call or WhatsApp '
          + window.MPC_CONTACT_PHONE + '.'
      };
    });
};
