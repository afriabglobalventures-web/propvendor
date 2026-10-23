/**
 * PropVendor Marketplace - Frontend JavaScript
 * Handles dynamic category-specific fields, multiple image previews, and pre-submission management.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        initCategoryFields();
        initMultipleImageUploads();
        initExistingImageToggles();
        initFormValidationAndScroll();
        initSearchFilters();
        initWalletCheckout();
        initInboxLeadUnlock();
        initSafetyNoticeDismiss();
        initAuthPortal();
        initPasswordToggles();
    });

    /**
     * 1. Dynamic Category-Specific Form Fields
     */
    function initCategoryFields() {
        var typeSelect = document.querySelector('select[name="propvendor_listing_type"]');
        if (!typeSelect) {
            return;
        }

        var propGroup = document.getElementById('propvendor-fields-property');
        var landGroup = document.getElementById('propvendor-fields-land');
        var vehGroup  = document.getElementById('propvendor-fields-vehicle');

        var groups = [
            { type: 'property_listing', el: propGroup },
            { type: 'land_listing',     el: landGroup },
            { type: 'vehicle_listing',  el: vehGroup }
        ];

        function updateVisibility() {
            var selectedType = typeSelect.value;

            // Synchronize Full Address requirement, label, and placeholder
            var addrInput = document.getElementById('propvendor_full_address');
            var addrLabelText = document.querySelector('.propvendor-address-label-text');
            var addrStar = document.querySelector('.propvendor-address-required-mark');

            if (addrInput) {
                if (selectedType === 'vehicle_listing') {
                    addrInput.required = false;
                    if (addrStar) {
                        addrStar.style.display = 'none';
                    }
                    if (addrLabelText) {
                        addrLabelText.textContent = 'Specific Location / Landmark';
                    }
                    addrInput.placeholder = 'e.g., Victoria Island, Lagos (or specific showroom/landmark)';
                } else {
                    addrInput.required = true;
                    if (addrStar) {
                        addrStar.style.display = 'inline';
                    }
                    if (addrLabelText) {
                        addrLabelText.textContent = 'Full Address';
                    }
                    addrInput.placeholder = 'e.g., House 14, Admiralty Way, Lekki Phase 1';
                }
            }

            // Synchronize dynamic Listing Title placeholder based on category
            var titleInput = document.querySelector('input[name="propvendor_title"]');
            if (titleInput) {
                if (selectedType === 'property_listing') {
                    titleInput.placeholder = 'e.g., Luxury 4 Bedroom Detached Duplex with Swimming Pool';
                } else if (selectedType === 'land_listing') {
                    titleInput.placeholder = 'e.g., 2 Plots of Dry Commercial Land along Express Road';
                } else if (selectedType === 'vehicle_listing') {
                    titleInput.placeholder = 'e.g., Clean Tokunbo 2020 Toyota Camry XLE';
                }
            }

            // Synchronize Land Plot Size requirement
            var landPlotInput = document.querySelector('#propvendor-fields-land input[name="propvendor_plot_size"]');
            if (landPlotInput) {
                landPlotInput.required = (selectedType === 'land_listing');
            }

            groups.forEach(function(item) {
                if (!item.el) {
                    return;
                }
                var isMatch = (item.type === selectedType);
                if (isMatch) {
                    item.el.style.display = 'block';
                    item.el.setAttribute('aria-hidden', 'false');
                    var controls = item.el.querySelectorAll('input, select, textarea');
                    controls.forEach(function(ctrl) {
                        ctrl.disabled = false;
                    });
                } else {
                    item.el.style.display = 'none';
                    item.el.setAttribute('aria-hidden', 'true');
                    var controls = item.el.querySelectorAll('input, select, textarea');
                    controls.forEach(function(ctrl) {
                        ctrl.disabled = true;
                    });
                }
            });
        }

        typeSelect.addEventListener('change', updateVisibility);
        // Initial setup on load
        updateVisibility();
    }

    /**
     * 2. Multiple Image Uploads & Client-Side Previews
     */
    function initMultipleImageUploads() {
        var fileInput = document.getElementById('propvendor_listing_images');
        var previewGrid = document.getElementById('propvendor-image-preview-grid');
        if (!fileInput || !previewGrid) {
            return;
        }

        var maxFiles = 10;
        var maxSizeBytes = 4 * 1024 * 1024; // 4MB
        var allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

        // Use DataTransfer to manage selected files if supported
        var dt = null;
        try {
            dt = new DataTransfer();
        } catch (e) {
            dt = null;
        }

        function renderPreviews() {
            previewGrid.innerHTML = '';
            var files = dt ? dt.files : fileInput.files;
            if (!files || files.length === 0) {
                return;
            }

            for (var i = 0; i < files.length; i++) {
                (function(index, file) {
                    var card = document.createElement('div');
                    card.className = 'propvendor-preview-card';

                    var img = document.createElement('img');
                    img.className = 'propvendor-preview-thumb';
                    img.alt = file.name;
                    var objectUrl = URL.createObjectURL(file);
                    img.src = objectUrl;

                    var meta = document.createElement('div');
                    meta.className = 'propvendor-preview-meta';
                    var sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                    meta.textContent = file.name + ' (' + sizeMB + ' MB)';

                    var removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'propvendor-preview-remove-btn';
                    removeBtn.setAttribute('aria-label', 'Remove ' + file.name);
                    removeBtn.textContent = '×';

                    removeBtn.addEventListener('click', function(ev) {
                        ev.preventDefault();
                        if (dt) {
                            var newDt = new DataTransfer();
                            for (var j = 0; j < dt.files.length; j++) {
                                if (j !== index) {
                                    newDt.items.add(dt.files[j]);
                                }
                            }
                            dt = newDt;
                            fileInput.files = dt.files;
                            renderPreviews();
                        } else {
                            // Fallback if DataTransfer is not supported
                            card.remove();
                        }
                    });

                    card.appendChild(img);
                    card.appendChild(meta);
                    card.appendChild(removeBtn);
                    previewGrid.appendChild(card);
                })(i, files[i]);
            }
        }

        fileInput.addEventListener('change', function(e) {
            if (!fileInput.files || fileInput.files.length === 0) {
                return;
            }

            if (dt) {
                var selectedFiles = Array.from(fileInput.files);
                var existingCount = dt.files.length;

                for (var i = 0; i < selectedFiles.length; i++) {
                    var file = selectedFiles[i];

                    // Check file type
                    if (allowedTypes.indexOf(file.type) === -1) {
                        alert('Only JPEG, PNG, and WebP images are allowed. File skipped: ' + file.name);
                        continue;
                    }

                    // Check file size
                    if (file.size > maxSizeBytes) {
                        alert('Image must be 4MB or smaller. File skipped: ' + file.name);
                        continue;
                    }

                    // Check max file limit
                    if (dt.items.length >= maxFiles) {
                        alert('You can upload a maximum of ' + maxFiles + ' images.');
                        break;
                    }

                    dt.items.add(file);
                }

                fileInput.files = dt.files;
            }

            renderPreviews();
        });
    }

    /**
     * 3. Existing Images Management (Edit Mode)
     */
    function initExistingImageToggles() {
        var removeCheckboxes = document.querySelectorAll('.propvendor-remove-image-checkbox');
        var fileInput = document.getElementById('propvendor_listing_images');

        function updateImageRequirements() {
            var nonDeletedCheckboxes = document.querySelectorAll('.propvendor-remove-image-checkbox:not(:checked)');
            var remainingExistingCount = nonDeletedCheckboxes.length;
            var newFilesCount = (fileInput && fileInput.files) ? fileInput.files.length : 0;

            if (fileInput) {
                if (remainingExistingCount === 0 && newFilesCount === 0) {
                    fileInput.required = true;
                } else {
                    fileInput.required = false;
                }
            }
        }

        removeCheckboxes.forEach(function(cb) {
            cb.addEventListener('change', function() {
                var card = cb.closest('.propvendor-existing-image-card');
                if (!card) {
                    return;
                }
                var radio = card.querySelector('input[name="propvendor_primary_image_id"]');

                if (cb.checked) {
                    card.classList.add('propvendor-image-marked-deleted');
                    if (radio && radio.checked) {
                        radio.checked = false;
                        // Select another existing image that is NOT marked deleted as primary
                        var availableCard = document.querySelector('.propvendor-existing-image-card:not(.propvendor-image-marked-deleted)');
                        if (availableCard) {
                            var otherRadio = availableCard.querySelector('input[name="propvendor_primary_image_id"]');
                            if (otherRadio) {
                                otherRadio.checked = true;
                            }
                        }
                    }
                } else {
                    card.classList.remove('propvendor-image-marked-deleted');
                    // If no radio is checked anywhere among existing images, check this one
                    var anyChecked = document.querySelector('input[name="propvendor_primary_image_id"]:checked');
                    if (!anyChecked && radio) {
                        radio.checked = true;
                    }
                }

                updateImageRequirements();
            });
        });

        if (fileInput) {
            fileInput.addEventListener('change', updateImageRequirements);
        }
    }

    /**
     * 4. HTML5 Validation Scrolling & Submit Button State
     */
    function initFormValidationAndScroll() {
        var forms = document.querySelectorAll('form.propvendor-form');
        forms.forEach(function(form) {
            var submitBtn = form.querySelector('button[type="submit"]');

            // Listen for native HTML5 'invalid' event on form inputs
            form.addEventListener('invalid', function(e) {
                if (submitBtn) {
                    submitBtn.classList.remove('is-loading', 'disabled');
                    submitBtn.disabled = false;
                }
                var target = e.target;
                if (target && typeof target.scrollIntoView === 'function') {
                    // Smoothly scroll to the first invalid field
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, true); // useCapture to catch invalid events from child inputs

            // On form submit, perform pre-validation checks
            form.addEventListener('submit', function(e) {
                // Check if existing images are all marked for deletion without replacement
                var removeCheckboxes = form.querySelectorAll('.propvendor-remove-image-checkbox');
                if (removeCheckboxes.length > 0) {
                    var nonDeletedCheckboxes = form.querySelectorAll('.propvendor-remove-image-checkbox:not(:checked)');
                    var fileInput = form.querySelector('#propvendor_listing_images');
                    var newFilesCount = (fileInput && fileInput.files) ? fileInput.files.length : 0;

                    if (nonDeletedCheckboxes.length === 0 && newFilesCount === 0) {
                        e.preventDefault();
                        alert('Every listing must have at least one image. Please upload a replacement image before deleting all existing images.');
                        if (fileInput) {
                            fileInput.required = true;
                            fileInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            fileInput.focus();
                        }
                        if (submitBtn) {
                            submitBtn.classList.remove('is-loading', 'disabled');
                            submitBtn.disabled = false;
                        }
                        return false;
                    }
                }
            });
        });
    }


    /**
     * 5. Dynamic Category Toggling in Search & Filter Bar
     */
    function initSearchFilters() {
        var searchForms = document.querySelectorAll('.propvendor-search-form, .propvendor-search-card form');
        if (!searchForms.length) {
            return;
        }

        var processedForms = [];

        searchForms.forEach(function(form) {
            if (processedForms.indexOf(form) !== -1) {
                return;
            }
            processedForms.push(form);

            var typeSelect = form.querySelector('select[name="pv_type"]');
            if (typeSelect) {
                var propRows     = form.querySelectorAll('.propvendor-filter-property-row');
                var landRows     = form.querySelectorAll('.propvendor-filter-land-row');
                var vehRows      = form.querySelectorAll('.propvendor-filter-vehicle-row');
                var titleDocWrap = form.querySelectorAll('.propvendor-filter-title-doc');

                function updateSearchFilterVisibility() {
                    var val = typeSelect.value;
                    var isProp = (val === 'property_listing' || val === 'property');
                    var isLand = (val === 'land_listing' || val === 'land');
                    var isVeh  = (val === 'vehicle_listing' || val === 'vehicle');
                    var isAll  = (!val || val === '');

                    // Property fields
                    propRows.forEach(function(el) {
                        el.style.display = (isProp || isAll) ? '' : 'none';
                    });

                    // Land fields
                    landRows.forEach(function(el) {
                        el.style.display = (isLand || isAll) ? '' : 'none';
                    });

                    // Vehicle fields
                    vehRows.forEach(function(el) {
                        el.style.display = isVeh ? '' : 'none';
                    });

                    // Title Document (relevant to Property and Land, but hidden for Vehicle)
                    titleDocWrap.forEach(function(el) {
                        el.style.display = isVeh ? 'none' : '';
                    });
                }

                typeSelect.addEventListener('change', updateSearchFilterVisibility);
                updateSearchFilterVisibility();
            }

            // Clean URL Parameters: disable empty inputs before form submission
            form.addEventListener('submit', function() {
                var fields = form.querySelectorAll('input, select, textarea');
                var disabledFields = [];

                fields.forEach(function(field) {
                    if (field.type === 'submit' || field.type === 'button' || field.type === 'reset') {
                        return;
                    }
                    if (field.name && (field.value === '' || (typeof field.value === 'string' && field.value.trim() === ''))) {
                        if (!field.disabled) {
                            field.disabled = true;
                            disabledFields.push(field);
                        }
                    }
                });

                // Re-enable disabled inputs shortly after if submission is cancelled or interrupted
                setTimeout(function() {
                    disabledFields.forEach(function(field) {
                        field.disabled = false;
                    });
                }, 500);
            });

            // Restore inputs on back-forward cache navigation
            window.addEventListener('pageshow', function() {
                var fields = form.querySelectorAll('input, select, textarea');
                fields.forEach(function(field) {
                    field.disabled = false;
                });
            });
        });
    }

    /**
     * 6. Vendor Wallet & Paystack Checkout Integration
     */
    function initWalletCheckout() {
        var walletApp = document.getElementById('propvendor-wallet-app');
        if (!walletApp) {
            return;
        }

        var noticeEl = document.getElementById('propvendor-wallet-notice');
        var checkoutButtons = walletApp.querySelectorAll('.propvendor-checkout-btn');

        function showNotice(type, msg) {
            if (!noticeEl) return;
            noticeEl.className = 'propvendor-wallet-notice notice-' + type;
            noticeEl.innerHTML = '<p>' + msg + '</p>';
            noticeEl.style.display = 'block';
            noticeEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        checkoutButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (btn.disabled) return;

                var planId = btn.getAttribute('data-plan-id');
                var originalText = btn.innerHTML;

                if (!window.propvendor_payment_vars) {
                    showNotice('error', 'Payment configuration is missing. Please refresh the page.');
                    return;
                }

                btn.disabled = true;
                btn.innerHTML = '<span class="propvendor-spinner"></span> Initializing...';
                if (noticeEl) {
                    noticeEl.style.display = 'none';
                }

                // 1. Create Checkout Order via AJAX
                var formData = new FormData();
                formData.append('action', 'propvendor_create_checkout_order');
                formData.append('plan_id', planId);
                formData.append('nonce', window.propvendor_payment_vars.nonce);

                fetch(window.propvendor_payment_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(response) {
                    if (!response || !response.success || !response.data) {
                        var err = (response && response.data && response.data.message) ? response.data.message : 'Unable to create checkout order.';
                        showNotice('error', err);
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                        return;
                    }

                    var order = response.data;
                    var paystackKey = order.paystack_public_key || window.propvendor_payment_vars.paystack_public_key;

                    if (!paystackKey) {
                        showNotice('error', 'Paystack Public Key is not configured. Please contact the site administrator.');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                        return;
                    }

                    if (typeof PaystackPop === 'undefined') {
                        showNotice('error', 'Payment gateway SDK could not be loaded. Please check your internet connection.');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                        return;
                    }

                    // 2. Open Paystack Inline Modal
                    var handler = PaystackPop.setup({
                        key: paystackKey,
                        email: order.vendor_email || window.propvendor_payment_vars.user_email,
                        amount: order.amount_kobo,
                        currency: 'NGN',
                        ref: order.reference,
                        metadata: {
                            custom_fields: [
                                { display_name: "Plan Name", variable_name: "plan_name", value: order.plan_name },
                                { display_name: "Order ID", variable_name: "order_id", value: String(order.order_id) }
                            ]
                        },
                        callback: function(paymentResponse) {
                            btn.innerHTML = '<span class="propvendor-spinner"></span> Verifying...';
                            showNotice('info', 'Payment successful! Verifying with Paystack and granting entitlements, please wait...');

                            var verifyData = new FormData();
                            verifyData.append('action', 'propvendor_verify_payment');
                            verifyData.append('reference', paymentResponse.reference || order.reference);
                            verifyData.append('nonce', window.propvendor_payment_vars.nonce);

                            fetch(window.propvendor_payment_vars.ajax_url, {
                                method: 'POST',
                                body: verifyData
                            })
                            .then(function(vRes) { return vRes.json(); })
                            .then(function(verifyResult) {
                                if (verifyResult && verifyResult.success) {
                                    showNotice('success', 'Payment verified successfully! Your account has been credited. Refreshing page...');
                                    setTimeout(function() {
                                        window.location.reload();
                                    }, 1500);
                                } else {
                                    var vErr = (verifyResult && verifyResult.data && verifyResult.data.message) ? verifyResult.data.message : 'Verification was not completed. Reference: ' + order.reference;
                                    showNotice('error', vErr);
                                    btn.disabled = false;
                                    btn.innerHTML = originalText;
                                }
                            })
                            .catch(function() {
                                showNotice('error', 'Network error during verification. Reference: ' + order.reference);
                                btn.disabled = false;
                                btn.innerHTML = originalText;
                            });
                        },
                        onClose: function() {
                            btn.disabled = false;
                            btn.innerHTML = originalText;
                            showNotice('warning', 'Payment window closed.');
                        }
                    });

                    handler.openIframe();
                })
                .catch(function() {
                    showNotice('error', 'Network error creating order. Please check your connection and try again.');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
            });
        });
    }

    /**
     * 7. Vendor Inbox Lead Contact Unlock Integration (Phase 5)
     */
    function initInboxLeadUnlock() {
        var triggers = document.querySelectorAll('.pv-btn-unlock-lead-trigger');
        if (!triggers.length) {
            return;
        }

        var modal = document.getElementById('pv-unlock-lead-modal');
        var modalBalance = document.getElementById('pv-modal-current-balance');
        var modalAfter = document.getElementById('pv-modal-balance-after');
        var modalError = document.getElementById('pv-unlock-modal-error');
        var confirmBtn = document.getElementById('pv-btn-confirm-unlock');
        var cancelBtn = document.getElementById('pv-btn-cancel-unlock');
        var closeBtn = modal ? modal.querySelector('.pv-unlock-modal-close') : null;
        var backdrop = modal ? modal.querySelector('.pv-unlock-modal-backdrop') : null;

        var activeThreadId = null;
        var activeBtn = null;

        function closeModal() {
            if (!modal) return;
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            if (modalError) {
                modalError.style.display = 'none';
                modalError.innerHTML = '';
            }
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = 'Confirm &amp; Deduct 1 Credit';
            }
            activeThreadId = null;
            activeBtn = null;
        }

        function openModal(threadId, btn) {
            if (!modal) return;
            activeThreadId = threadId;
            activeBtn = btn;

            var bal = parseInt(btn.getAttribute('data-balance'), 10) || 0;
            if (modalBalance) modalBalance.textContent = bal;
            if (modalAfter) modalAfter.textContent = Math.max(0, bal - 1);
            if (modalError) modalError.style.display = 'none';

            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            if (confirmBtn) confirmBtn.focus();
        }

        triggers.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var threadId = btn.getAttribute('data-thread-id');
                var bal = parseInt(btn.getAttribute('data-balance'), 10) || 0;

                if (bal < 1) {
                    if (window.propvendor_payment_vars && window.propvendor_payment_vars.wallet_url) {
                        window.location.href = window.propvendor_payment_vars.wallet_url;
                    }
                    return;
                }

                openModal(threadId, btn);
            });
        });

        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (backdrop) backdrop.addEventListener('click', closeModal);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal && modal.style.display === 'flex') {
                closeModal();
            }
        });

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (!activeThreadId || confirmBtn.disabled) return;

                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<span class="propvendor-spinner"></span> Unlocking...';
                if (modalError) modalError.style.display = 'none';

                var formData = new FormData();
                formData.append('action', 'propvendor_unlock_thread_lead');
                formData.append('thread_id', activeThreadId);
                formData.append('nonce', window.propvendor_payment_vars.unlock_nonce || window.propvendor_payment_vars.nonce);

                fetch(window.propvendor_payment_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(response) {
                    if (!response || !response.success || !response.data) {
                        var msg = (response && response.data && response.data.message) ? response.data.message : 'Failed to unlock contact. Please try again.';
                        if (modalError) {
                            modalError.innerHTML = msg;
                            modalError.style.display = 'block';
                        }
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = 'Confirm &amp; Deduct 1 Credit';
                        return;
                    }

                    var data = response.data;
                    closeModal();

                    // 1. Reveal unmasked email
                    var emailEl = document.getElementById('pv-contact-email-' + activeThreadId);
                    if (emailEl && data.customer_email) {
                        emailEl.innerHTML = '<strong>Email:</strong> <a href="mailto:' + encodeURI(data.customer_email) + '">' + escapeHtml(data.customer_email) + '</a>';
                    }

                    // 2. Reveal unmasked phone
                    var phoneEl = document.getElementById('pv-contact-phone-' + activeThreadId);
                    if (phoneEl && data.customer_phone) {
                        phoneEl.innerHTML = '<strong>Phone:</strong> <a href="tel:' + encodeURI(data.customer_phone) + '">' + escapeHtml(data.customer_phone) + '</a>';
                    }

                    // 3. Replace unlock box with Unlocked Badge
                    var box = document.getElementById('pv-unlock-box-' + activeThreadId);
                    if (box) {
                        box.innerHTML = '<div class="pv-unlock-success-banner"><span class="dashicons dashicons-yes-alt"></span> <strong>Lead Contact Details Unlocked!</strong> You have permanent direct access to this customer.</div>';
                    }

                    // 4. Update all balance pills / displays
                    var balancePills = document.querySelectorAll('.pv-unlock-balance-pill, #pv-current-credit-balance');
                    balancePills.forEach(function(pill) {
                        if (pill.id === 'pv-current-credit-balance') {
                            pill.textContent = data.balance;
                        } else {
                            pill.textContent = 'Balance: ' + data.balance + ' Credits';
                        }
                    });

                    // Update data-balance on all remaining unlock buttons
                    var remainingBtns = document.querySelectorAll('.pv-btn-unlock-lead-trigger');
                    remainingBtns.forEach(function(rb) {
                        rb.setAttribute('data-balance', data.balance);
                    });
                })
                .catch(function() {
                    if (modalError) {
                        modalError.innerHTML = 'Network error during unlock request. Please check your connection and try again.';
                        modalError.style.display = 'block';
                    }
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = 'Confirm &amp; Deduct 1 Credit';
                });
            });
        }

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    }

    /**
     * 8. Dismissible Anti-Advance-Fee Safety Notice
     */
    function initSafetyNoticeDismiss() {
        var storageKey = 'pv_safety_notice_dismissed';

        try {
            if (localStorage.getItem(storageKey) === '1') {
                var notices = document.querySelectorAll('.propvendor-safety-notice-card');
                notices.forEach(function(card) {
                    card.style.display = 'none';
                    card.classList.add('pv-dismissed');
                });
            }
        } catch (err) {}

        document.addEventListener('click', function(e) {
            var closeBtn = e.target.closest('#pv-safety-notice-close, .pv-safety-notice-close');
            if (!closeBtn) {
                return;
            }

            e.preventDefault();
            var card = closeBtn.closest('.propvendor-safety-notice-card');
            if (card) {
                card.style.display = 'none';
                card.classList.add('pv-dismissed');
            }

            try {
                localStorage.setItem(storageKey, '1');
            } catch (err) {}
        });
    }

    /**
     * 9. Phase 12A Secure Front-End Authentication Portal
     */
    function initAuthPortal() {
        var portal = document.querySelector('.propvendor-auth-portal-wrapper');
        if (!portal) {
            return;
        }

        // Tab Switching
        var tabBtns = portal.querySelectorAll('.pv-auth-tab-btn');
        var tabPanels = portal.querySelectorAll('.pv-auth-tab-panel');

        function switchTab(targetTab) {
            tabBtns.forEach(function(btn) {
                var isTarget = btn.getAttribute('data-tab') === targetTab;
                btn.classList.toggle('active', isTarget);
                btn.setAttribute('aria-selected', isTarget ? 'true' : 'false');
            });

            tabPanels.forEach(function(panel) {
                var isTarget = panel.id === ('pv-auth-panel-' + targetTab);
                panel.classList.toggle('active', isTarget);
                panel.style.display = isTarget ? 'block' : 'none';
            });

            // Update URL without reloading page if supported
            if (window.history && window.history.replaceState) {
                var currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('tab', targetTab);
                window.history.replaceState(null, '', currentUrl.toString());
            }
        }

        tabBtns.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var tab = this.getAttribute('data-tab');
                if (tab) {
                    switchTab(tab);
                }
            });
        });

        // Forgot Password and Back to Sign In links
        var forgotLinks = portal.querySelectorAll('.pv-forgot-password-link');
        forgotLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                switchTab('forgot');
            });
        });

        var backLinks = portal.querySelectorAll('.pv-link-back');
        backLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                switchTab('login');
            });
        });

        // Password Show/Hide Toggles
        initPasswordToggles(portal);

        // Account Type Toggle (Customer vs Vendor)
        var roleRadios = portal.querySelectorAll('input[name="account_type"]');
        var roleCards = portal.querySelectorAll('.pv-role-card');
        var vendorFields = document.getElementById('pv-vendor-reg-fields');
        var expCustomer = document.getElementById('pv-exp-customer');
        var expVendor = document.getElementById('pv-exp-vendor');
        var bnameInput = document.getElementById('pv-reg-bname');
        var phoneInput = document.getElementById('pv-reg-phone');

        function updateRoleView() {
            var selectedRole = 'customer';
            roleRadios.forEach(function(radio) {
                var card = radio.closest('.pv-role-card');
                if (radio.checked) {
                    selectedRole = radio.value;
                    if (card) {
                        card.classList.add('is-selected', 'selected', 'active');
                    }
                } else {
                    if (card) {
                        card.classList.remove('is-selected', 'selected', 'active');
                    }
                }
            });

            if (selectedRole === 'vendor') {
                if (vendorFields) {
                    vendorFields.style.display = 'block';
                }
                if (expVendor) {
                    expVendor.style.display = 'block';
                    expVendor.classList.add('active');
                }
                if (expCustomer) {
                    expCustomer.style.display = 'none';
                    expCustomer.classList.remove('active');
                }
                if (bnameInput) {
                    bnameInput.required = true;
                }
                if (phoneInput) {
                    phoneInput.required = true;
                }
            } else {
                if (vendorFields) {
                    vendorFields.style.display = 'none';
                }
                if (expVendor) {
                    expVendor.style.display = 'none';
                    expVendor.classList.remove('active');
                }
                if (expCustomer) {
                    expCustomer.style.display = 'block';
                    expCustomer.classList.add('active');
                }
                if (bnameInput) {
                    bnameInput.required = false;
                }
                if (phoneInput) {
                    phoneInput.required = false;
                }
            }

            // Switch the benefits banner text dynamically if title element exists
            var bannerTitle = portal.querySelector('.pv-benefits-banner-title, .pv-benefits-title');
            if (bannerTitle) {
                bannerTitle.textContent = (selectedRole === 'vendor') ? 'Vendor Account Benefits' : 'Customer Account Benefits';
            }
        }

        roleRadios.forEach(function(radio) {
            radio.addEventListener('change', updateRoleView);
        });

        // Ensure clicking anywhere on the card toggles the corresponding radio input
        roleCards.forEach(function(card) {
            card.addEventListener('click', function(e) {
                var radio = this.querySelector('input[type="radio"][name="account_type"]');
                if (radio) {
                    if (!radio.checked) {
                        radio.checked = true;
                        updateRoleView();
                        var changeEvt = new Event('change', { bubbles: true });
                        radio.dispatchEvent(changeEvt);
                    } else {
                        updateRoleView();
                    }
                }
            });
        });

        // Initial sync of role view
        if (roleRadios.length > 0) {
            updateRoleView();
        }
    }

    /**
     * 10. Global Password Visibility Toggles
     */
    function initPasswordToggles(context) {
        var root = context || document;
        var pwdToggles = root.querySelectorAll('.pv-toggle-pwd-btn');
        pwdToggles.forEach(function(btn) {
            if (btn.getAttribute('data-pwd-init') === '1') {
                return;
            }
            btn.setAttribute('data-pwd-init', '1');
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var targetId = this.getAttribute('data-toggle-target');
                var input = targetId ? document.getElementById(targetId) : null;
                if (!input) {
                    var wrap = this.closest('.pv-pwd-wrap');
                    if (wrap) {
                        input = wrap.querySelector('input');
                    }
                }
                if (!input) {
                    return;
                }

                if (input.type === 'password') {
                    input.type = 'text';
                    this.setAttribute('aria-label', 'Hide password');
                    this.innerHTML = '&#128064;';
                } else {
                    input.type = 'password';
                    this.setAttribute('aria-label', 'Show password');
                    this.innerHTML = '&#128065;';
                }
            });
        });
    }

})();
