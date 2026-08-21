(function () {
  function aboutHtml() {
    return ''
      + '<div class="arosoft-about-panel">'
      + '<h2>AROSOFT Mail</h2>'
      + '<p class="lead">Managed by <strong>AROSOFT Innovations Ltd</strong>.</p>'
      + '<p>Secure webmail, mailbox self-service, and domain email hosting for organizations.</p>'
      + '<p>This mail platform is configured as a general hosted mail service and is not limited to a single customer domain.</p>'
      + '<p><a class="button mainaction" href="https://arosoftlabs.com" target="_blank" rel="noopener">AROSOFT Innovations Ltd</a></p>'
      + '</div>';
  }

  function replaceAboutContent() {
    var body = document.body || null;
    if (!body || !body.classList.contains('action-about')) return;
    var frame = document.querySelector('.frame-content');
    if (frame) frame.innerHTML = aboutHtml();
  }

  function patchDialog() {
    if (!window.UI || !window.rcmail) return;
    UI.about_dialog = function () {
      rcmail.show_popup_dialog(aboutHtml(), 'About AROSOFT Mail', [{ text: rcmail.gettext('close'), click: function(e) { rcmail.hide_popup_dialog(e); }}], { width: 560, height: 360 });
      return false;
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', replaceAboutContent);
  } else {
    replaceAboutContent();
  }
  if (window.rcmail && rcmail.addEventListener) {
    rcmail.addEventListener('init', function () {
      patchDialog();
      replaceAboutContent();
    });
  }
  setTimeout(patchDialog, 250);
})();
