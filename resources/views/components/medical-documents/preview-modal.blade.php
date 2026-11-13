{{-- Shared image preview window for medical document cards; filled by resources/js/app.js from the clicked thumbnail. --}}
<div class="modal fade" id="document-preview" tabindex="-1" aria-labelledby="document-preview-title" aria-hidden="true" data-document-preview>
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5 text-truncate" id="document-preview-title" data-preview-modal-title>Document preview</h2>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body document-preview__body">
                <img class="document-preview__image" alt="" data-preview-modal-image>
            </div>
            <div class="modal-footer">
                <a class="btn btn-outline-primary" href="#" data-preview-modal-download>
                    <i class="fa-solid fa-download me-1" aria-hidden="true"></i>Download
                </a>
                <button class="btn btn-primary" type="button" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
