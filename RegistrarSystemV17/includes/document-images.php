<?php
/**
 * includes/document-images.php
 *
 * Shared, read-only presentation helper for the document images that
 * students upload through the online request form (stored by
 * includes/upload-helper.php, saved into the *_image columns on
 * `online_requests`, `requests` and `archive_history`).
 *
 * Used by online-request.php (staff review the uploads before
 * approving), requests.php (staff verify them again while processing)
 * and archives.php. Nothing here writes or deletes -- deleting uploads
 * is upload-helper.php's job.
 *
 * Usage in a page:
 *
 *   require_once 'includes/document-images.php';
 *   ...
 *   <?php echo render_submitted_documents($request); ?>   // inside the details modal
 *   ...
 *   <?php echo render_document_lightbox(); ?>             // ONCE, just before </body>
 */

require_once __DIR__ . '/storage.php';

/**
 * Column name => label shown to staff. The labels mirror the ones a
 * student sees on request-online.php, so "Photocopy of Document - Set 2"
 * on screen here is the same field they uploaded under that name.
 *
 * The order of this array is the order the tiles appear in.
 */
const DOCUMENT_IMAGE_LABELS = [
    'student_id_image'           => 'Valid CvSU Student ID',
    'registration_form_image'    => 'Registration Form',
    'clearance_form_image'       => 'Registrar Clearance Form',
    'proof_of_graduation_image'  => 'Proof of Graduation',
    'id_picture_image'           => '2x2 ID Picture',
    'honorable_dismissal_image'  => 'Honorable Dismissal',
    'government_id_image'        => 'Government-issued ID',
    'original_document_image'    => 'Original Document',
    'photocopy_document_image'   => 'Photocopy of Document - Set 1',
    'photocopy_document_image_2' => 'Photocopy of Document - Set 2',
];

/**
 * How many document images a row actually has stored. Handy for an
 * at-a-glance count in a table row ("3 documents") so staff know whether
 * opening the details modal is worth it.
 *
 * Counts what's in the DB, including rows whose file has since gone
 * missing from disk -- those still show up in the modal, flagged.
 */
function count_submitted_documents(array $row): int
{
    $count = 0;
    foreach (array_keys(DOCUMENT_IMAGE_LABELS) as $column) {
        if (!empty($row[$column])) {
            $count++;
        }
    }
    return $count;
}

/**
 * Physical file for a stored logical path (e.g. 'uploads/documents/x.jpg'),
 * looked up in private storage first and the legacy uploads/ folder second
 * (see includes/storage.php). Returns null if the path is invalid or the
 * file does not exist.
 */
function document_image_absolute_path(string $relativePath): ?string
{
    return resolve_stored_file($relativePath);
}

/**
 * Render the "Submitted Documents" block for one request row.
 *
 * Returns '' when the row has no uploads at all -- requests created
 * before image uploads existed, and every walk-in request, land here
 * with all ten columns NULL, and must not render an empty heading. The
 * caller can therefore do:
 *
 *   <?php if (($docs = render_submitted_documents($request)) !== ''): ?>
 *       <?php echo $docs; ?>
 *   <?php endif; ?>
 *
 * ...or just echo it unconditionally, since '' prints nothing.
 *
 * @param array  $row     A row from online_requests / requests /
 *                        archive_history (SELECT * -- the *_image
 *                        columns must be present).
 * @param string $heading Section heading; pass '' to omit it.
 */
function render_submitted_documents(array $row, string $heading = 'Submitted Documents'): string
{
    $tiles = '';

    foreach (DOCUMENT_IMAGE_LABELS as $column => $label) {
        $relativePath = trim((string)($row[$column] ?? ''));
        if ($relativePath === '') {
            continue; // not required for this document type, or never uploaded
        }

        $absolute  = document_image_absolute_path($relativePath);
        $onDisk    = $absolute !== null && is_file($absolute);
        $safeLabel = htmlspecialchars($label, ENT_QUOTES);
        // Uploads have no public URL; they are streamed by serve-document.php
        // after a login check. $relativePath stays the logical DB path.
        $safeSrc   = htmlspecialchars('serve-document.php?f=' . rawurlencode($relativePath), ENT_QUOTES);

        if (!$onDisk) {
            // The DB says there's a file but it isn't there any more.
            // Say so plainly instead of rendering a broken image icon --
            // staff need to know the difference between "student didn't
            // upload this" and "the file is gone".
            $tiles .= '
                <div class="doc-tile doc-tile-missing">
                    <div class="doc-thumb-frame doc-thumb-missing">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>File not found</span>
                    </div>
                    <div class="doc-tile-label">' . $safeLabel . '</div>
                </div>';
            continue;
        }

        $tiles .= '
            <div class="doc-tile">
                <button type="button" class="doc-thumb-frame doc-thumb-open"
                        data-doc-src="' . $safeSrc . '"
                        data-doc-label="' . $safeLabel . '"
                        title="Click to zoom">
                    <img src="' . $safeSrc . '" alt="' . $safeLabel . '" loading="lazy" decoding="async">
                    <span class="doc-thumb-zoom"><i class="fas fa-magnifying-glass-plus"></i></span>
                </button>
                <div class="doc-tile-label">' . $safeLabel . '</div>
            </div>';
    }

    if ($tiles === '') {
        return '';
    }

    $headingHtml = $heading !== ''
        ? '<h6>' . htmlspecialchars($heading, ENT_QUOTES) . '</h6>'
        : '';

    return $headingHtml . '<div class="submitted-documents">' . $tiles . '</div>';
}

/**
 * Markup + styles + behaviour for the zoom overlay. Call this ONCE per
 * page, just before </body>.
 *
 * Deliberately NOT a Bootstrap modal: the thumbnails live inside the
 * details modal, and Bootstrap 5 doesn't stack modals cleanly (the
 * backdrop and body scroll-lock of the second one fight the first).
 * A plain fixed overlay above Bootstrap's z-index sidesteps that.
 *
 * The click handler is delegated from `document`, so it also works for
 * thumbnails injected later by the AJAX search on requests.php, which
 * replaces #ajaxModalsContainer wholesale.
 */
function render_document_lightbox(): string
{
    return <<<'HTML'
<style>
    .submitted-documents {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 14px;
        margin-bottom: 1rem;
    }
    .doc-tile-label {
        font-size: 0.8rem;
        margin-top: 6px;
        line-height: 1.25;
        word-break: break-word;
    }
    .doc-thumb-frame {
        position: relative;
        display: block;
        width: 100%;
        height: 110px;
        padding: 0;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        overflow: hidden;
        background: #f8f9fa;
    }
    .doc-thumb-open { cursor: zoom-in; }
    .doc-thumb-open img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .doc-thumb-zoom {
        position: absolute;
        right: 6px;
        bottom: 6px;
        background: rgba(0, 0, 0, 0.6);
        color: #fff;
        border-radius: 4px;
        padding: 2px 6px;
        font-size: 0.75rem;
        opacity: 0;
        transition: opacity 0.15s ease;
    }
    .doc-thumb-open:hover .doc-thumb-zoom,
    .doc-thumb-open:focus .doc-thumb-zoom { opacity: 1; }
    .doc-thumb-missing {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 6px;
        color: #b02a37;
        font-size: 0.8rem;
        border-style: dashed;
    }

    #documentLightbox {
        position: fixed;
        inset: 0;
        /* Above Bootstrap's modal (1055) so it works while the details
           modal is open underneath. */
        z-index: 2000;
        background: rgba(0, 0, 0, 0.85);
        display: none;
        flex-direction: column;
    }
    #documentLightbox.open { display: flex; }
    #documentLightbox .lightbox-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 12px 18px;
        color: #fff;
        background: rgba(0, 0, 0, 0.4);
    }
    #documentLightbox .lightbox-title { font-weight: 600; }
    #documentLightbox .lightbox-actions { display: flex; gap: 10px; align-items: center; }
    #documentLightbox .lightbox-actions a,
    #documentLightbox .lightbox-actions button {
        color: #fff;
        background: transparent;
        border: 1px solid rgba(255, 255, 255, 0.5);
        border-radius: 4px;
        padding: 4px 12px;
        font-size: 0.85rem;
        text-decoration: none;
        cursor: pointer;
    }
    #documentLightbox .lightbox-body {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 18px;
        overflow: auto;
    }
    #documentLightbox .lightbox-body img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        background: #fff;
    }
</style>

<div id="documentLightbox" aria-hidden="true" role="dialog" aria-label="Document preview">
    <div class="lightbox-bar">
        <span class="lightbox-title" id="documentLightboxTitle"></span>
        <span class="lightbox-actions">
            <!-- Browser-native zoom is better than anything reimplemented
                 here for reading a blurry student number. -->
            <a id="documentLightboxOpen" href="#" target="_blank" rel="noopener">Open in new tab</a>
            <button type="button" id="documentLightboxClose">Close</button>
        </span>
    </div>
    <div class="lightbox-body">
        <img id="documentLightboxImage" src="" alt="">
    </div>
</div>

<script>
(function () {
    const overlay  = document.getElementById('documentLightbox');
    if (!overlay) return;

    const image    = document.getElementById('documentLightboxImage');
    const title    = document.getElementById('documentLightboxTitle');
    const openLink = document.getElementById('documentLightboxOpen');

    function openLightbox(src, label) {
        image.src = src;
        image.alt = label;
        title.textContent = label;
        openLink.href = src;
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeLightbox() {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        // Drop the src so a large image isn't kept decoded in memory
        // while the overlay is hidden.
        image.src = '';
    }

    // Delegated: thumbnails can arrive later via the AJAX search on
    // requests.php, which replaces the modal container's innerHTML.
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('.doc-thumb-open');
        if (trigger) {
            e.preventDefault();
            openLightbox(trigger.dataset.docSrc, trigger.dataset.docLabel || 'Document');
            return;
        }

        if (e.target.id === 'documentLightboxClose') {
            closeLightbox();
            return;
        }

        // Click on the dark backdrop (but not the image or the bar).
        if (e.target === overlay || e.target.classList.contains('lightbox-body')) {
            closeLightbox();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) {
            closeLightbox();
        }
    });
})();
</script>
HTML;
}