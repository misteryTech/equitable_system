<?php
/**
 * Right-hand column of the Service / ATW Request generators: the recipients box
 * and the live preview with its three copy buttons. The page's script fills
 * #egRecipients (EmailGen.RecipientsBox), #egSubject and #egPreview.
 */
?>
          <div class="card">
            <div class="card-body">
              <h5 class="card-title pb-2">Recipients</h5>
              <div id="egRecipients"></div>
            </div>
          </div>

          <div class="card">
            <div class="card-body">
              <h5 class="card-title d-flex align-items-center">Preview <a href="my_requests.php" class="ms-auto small fw-normal">My Requests</a></h5>
              <div class="small text-muted mb-1">Subject</div>
              <div id="egSubject" class="border rounded px-3 py-2 bg-light mb-2 text-break">—</div>
              <div class="small text-muted mb-1">Email body</div>
              <pre id="egPreview" class="border rounded p-3 bg-light mb-3" style="white-space: pre-wrap; word-break: break-word; min-height: 12rem; font-family: inherit; font-size: .95rem"></pre>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary" id="egCopyRecipients"><i class="bi bi-people"></i> Copy Recipients</button>
                <button type="button" class="btn btn-outline-primary" id="egCopySubject"><i class="bi bi-type"></i> Copy Subject</button>
                <button type="button" class="btn btn-primary" id="egCopyBody"><i class="bi bi-clipboard"></i> Copy Email Body</button>
              </div>
              <div class="small text-muted mt-2" id="egStatus">Nothing is sent from here — paste the recipients, subject and body into Gmail.</div>
            </div>
          </div>
