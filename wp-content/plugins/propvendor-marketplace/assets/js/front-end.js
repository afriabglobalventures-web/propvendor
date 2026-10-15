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

})();
