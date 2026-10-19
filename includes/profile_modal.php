<?php
/**
 * Edit Profile modal for the signed-in user (engineers and admins).
 * Included by includes/header.php. Open it with any element that has
 * data-bs-toggle="modal" data-bs-target="#profileModal".
 * Expects $headerUser (current_user()).
 */

// Declared for the editor's code checker; includes/header.php sets it.
/** @var array $headerUser */
?>
  <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
      <form class="modal-content" id="profileForm" enctype="multipart/form-data" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="profileModalTitle">Edit Profile</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <?= csrf_field() ?>
          <div class="alert alert-danger d-none" id="profileErrors" role="alert"></div>

          <div class="row g-3">
            <!-- Picture -->
            <div class="col-12 d-flex align-items-center gap-3">
              <img src="<?= e(profile_picture_url($headerUser)) ?>" alt="Profile picture" id="profileModalPreview"
                   class="rounded-circle border" style="width: 88px; height: 88px; object-fit: cover"
                   data-current="<?= e(profile_picture_url($headerUser)) ?>" data-default="<?= e(PROFILE_PICTURE_DEFAULT) ?>">
              <div class="flex-grow-1">
                <label for="profileModalPicture" class="form-label mb-1">Profile Picture</label>
                <input type="file" name="profile_picture" id="profileModalPicture" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,image/gif">
                <div class="form-text">JPG, PNG, WEBP or GIF, up to 5 MB.</div>
<?php if ($headerUser['profile_picture']): ?>
                <div class="form-check mt-1">
                  <input class="form-check-input" type="checkbox" name="remove_picture" value="1" id="profileModalRemove">
                  <label class="form-check-label small" for="profileModalRemove">Remove my picture</label>
                </div>
<?php endif; ?>
              </div>
            </div>

            <!-- Name and email -->
            <div class="col-md-4">
              <label for="pmFirst" class="form-label">First Name</label>
              <input type="text" name="first_name" id="pmFirst" class="form-control" maxlength="50" required value="<?= e($headerUser['first_name']) ?>">
            </div>
            <div class="col-md-4">
              <label for="pmMiddle" class="form-label">Middle Name <span class="text-muted small">(optional)</span></label>
              <input type="text" name="middle_name" id="pmMiddle" class="form-control" maxlength="50" value="<?= e($headerUser['middle_name']) ?>">
            </div>
            <div class="col-md-4">
              <label for="pmLast" class="form-label">Last Name</label>
              <input type="text" name="last_name" id="pmLast" class="form-control" maxlength="50" required value="<?= e($headerUser['last_name']) ?>">
            </div>
            <div class="col-12">
              <label for="pmEmail" class="form-label">Email Address</label>
              <input type="email" name="email" id="pmEmail" class="form-control" maxlength="100" required value="<?= e($headerUser['email']) ?>">
            </div>
            <div class="col-md-6">
              <label for="pmMobile" class="form-label">Mobile Number</label>
              <input type="tel" name="mobile_number" id="pmMobile" class="form-control" maxlength="20" placeholder="e.g. 09171234567" value="<?= e($headerUser['mobile_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label for="pmArea" class="form-label">Area / Assignment</label>
              <input type="text" name="area" id="pmArea" class="form-control" maxlength="100" placeholder="e.g. Cotabato City" value="<?= e($headerUser['area'] ?? '') ?>">
            </div>
            <div class="col-12 form-text mt-1">Your name (as "First M. Last"), mobile number and area are used in the sign-off of Email Generator emails ("F.E <?= e(mb_strtoupper($headerUser['area'] ?? '') ?: 'AREA') ?>", "<?= e(mb_strtoupper($headerUser['area'] ?? '') ?: 'AREA') ?> FE").</div>

            <!-- Admin-controlled details (read only) -->
            <div class="col-12">
              <div class="bg-light rounded p-2 small">
                <div class="row g-1">
                  <div class="col-sm-6"><span class="text-muted">Username:</span> @<?= e($headerUser['username']) ?></div>
                  <div class="col-sm-6"><span class="text-muted">Role:</span> <?= e(role_label($headerUser['role'])) ?></div>
<?php if ($headerUser['field_engineer_id']): ?>
                  <div class="col-sm-6"><span class="text-muted">Field Engineer ID:</span> <?= e($headerUser['field_engineer_id']) ?></div>
<?php endif; ?>
                  <div class="col-sm-6"><span class="text-muted">Location:</span> <?= e($headerUser['location']) ?></div>
                </div>
                <div class="text-muted mt-1">Contact an admin to change these.</div>
              </div>
            </div>

            <!-- Password (optional) -->
            <div class="col-12">
              <a class="small" data-bs-toggle="collapse" href="#pmPassword" role="button" aria-expanded="false" aria-controls="pmPassword">
                <i class="bi bi-key"></i> Change password
              </a>
              <div class="collapse" id="pmPassword">
                <div class="row g-2 mt-1">
                  <div class="col-md-4">
                    <label for="pmCurrent" class="form-label small mb-1">Current Password</label>
                    <input type="password" name="current_password" id="pmCurrent" class="form-control form-control-sm" autocomplete="current-password">
                  </div>
                  <div class="col-md-4">
                    <label for="pmNew" class="form-label small mb-1">New Password</label>
                    <input type="password" name="new_password" id="pmNew" class="form-control form-control-sm" minlength="8" autocomplete="new-password">
                  </div>
                  <div class="col-md-4">
                    <label for="pmConfirm" class="form-label small mb-1">Confirm New Password</label>
                    <input type="password" name="confirm_password" id="pmConfirm" class="form-control form-control-sm" minlength="8" autocomplete="new-password">
                  </div>
                  <div class="col-12 form-text mt-0">Leave blank to keep your current password.</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="profileSave"><i class="bi bi-save"></i> Save Profile</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      var form = document.getElementById("profileForm");
      var errors = document.getElementById("profileErrors");
      var save = document.getElementById("profileSave");
      var picture = document.getElementById("profileModalPicture");
      var preview = document.getElementById("profileModalPreview");
      var remove = document.getElementById("profileModalRemove");

      function showErrors(list) {
        errors.innerHTML = "";
        var ul = document.createElement("ul");
        ul.className = "mb-0";
        list.forEach(function (text) {
          var li = document.createElement("li");
          li.textContent = text;
          ul.appendChild(li);
        });
        errors.appendChild(ul);
        errors.classList.remove("d-none");
        errors.scrollIntoView({ block: "nearest" });
      }

      picture.addEventListener("change", function () {
        var file = picture.files[0];
        if (file && file.size > 5 * 1024 * 1024) {
          showErrors(["Profile picture must be 5 MB or smaller."]);
          picture.value = "";
          file = null;
        }
        if (file && remove) { remove.checked = false; }
        preview.src = file ? URL.createObjectURL(file) : preview.dataset.current;
      });
      if (remove) {
        remove.addEventListener("change", function () {
          if (remove.checked) { picture.value = ""; }
          preview.src = remove.checked ? preview.dataset.default : preview.dataset.current;
        });
      }

      form.addEventListener("submit", function (e) {
        e.preventDefault();
        errors.classList.add("d-none");
        save.disabled = true;
        fetch("profile_update.php", { method: "POST", body: new FormData(form) })
          .then(function (res) {
            return res.json().catch(function () { return { errors: ["Unexpected server response (" + res.status + ")."] }; });
          })
          .then(function (data) {
            if (data.errors) { throw data.errors; }
            // Reload so the new name and picture show in the top bar and on the page.
            sessionStorage.setItem("profileSaved", data.message || "Profile saved.");
            location.reload();
          })
          .catch(function (err) {
            showErrors(Array.isArray(err) ? err : ["Could not save. Please check your connection and try again."]);
            save.disabled = false;
          });
      });

      // After the reload, confirm the save at the top of the page.
      var saved = null;
      try { saved = sessionStorage.getItem("profileSaved"); sessionStorage.removeItem("profileSaved"); } catch (e) {}
      if (saved) {
        document.addEventListener("DOMContentLoaded", function () {
          var main = document.getElementById("main");
          if (!main) { return; }
          var alert = document.createElement("div");
          alert.className = "alert alert-success alert-dismissible fade show";
          alert.setAttribute("role", "status");
          alert.textContent = saved;
          var close = document.createElement("button");
          close.type = "button";
          close.className = "btn-close";
          close.setAttribute("data-bs-dismiss", "alert");
          close.setAttribute("aria-label", "Close");
          alert.appendChild(close);
          main.insertBefore(alert, main.firstChild);
        });
      }
    })();
  </script>
