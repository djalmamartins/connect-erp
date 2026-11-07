/** Organic UI v2 — Organic Editor bridge
 * Integrates the full Organic Editor package while preserving window.Organic.Editor.
 */
import OrganicEditor from "../../../packages/organic-editor/modules/public-api.js";

window.Organic = window.Organic || {};
window.Organic.Editor = OrganicEditor;
window.OrganicEditor = OrganicEditor;

function initDeclarativeEditors() {
  document.querySelectorAll('[data-org-editor-full]').forEach((target) => {
    if (target.dataset.organicEditorId) return;
    const preset = target.dataset.orgEditorPreset || 'full';
    const mode = target.dataset.orgEditorMode || 'document';
    OrganicEditor.init({ target, preset, mode });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initDeclarativeEditors);
} else {
  initDeclarativeEditors();
}

export { OrganicEditor };
export default OrganicEditor;
