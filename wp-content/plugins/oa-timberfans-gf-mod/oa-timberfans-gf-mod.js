/**
 * OA TimberFans GF Mod JavaScript
 *
 * Handles dynamic form field filtering and interactions
 *
 * @package OA_TimberFans_GF_Mod
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * OA TimberFans GF Mod Class
     */
    class OATimberFansGFMod {
        
        /**
         * Constructor
         */
        constructor() {
            this.init();
        }
        
        /**
         * Initialize the plugin
         */
        init() {
            this.bindEvents();
        }
        
        /**
         * Bind event listeners
         */
        bindEvents() {
            $(document).on('gform_post_render', this.handleFormRender.bind(this));
        }
        

        
        /**
         * Handle form render event
         *
         * @param {Event} event - The form render event
         * @param {number} formId - The form ID
         */
        handleFormRender(event, formId) {
            console.log('OA TimberFans: gform_post_render triggered', { 
                formId, 
                expectedFormId: OATF.form_id 
            });
            
            if (formId != OATF.form_id) {
                console.log('OA TimberFans: Form ID mismatch, exiting');
                return;
            }
            
            this.applySharedClasses(formId);
            this.setupChangeListener(formId);
            this.checkExistingSelections(formId);
        }
        
        /**
         * Setup change listener for product field
         *
         * @param {number} formId - The form ID
         */
        setupChangeListener(formId) {
            console.log('OA TimberFans: Setting up change listener for field', OATF.field_product);
            
            const $productField = $(`#field_${formId}_${OATF.field_product}`);
            
            $productField.on('change', 'input[type=radio]', (event) => {
                const productId = $(event.target).val();
                this.handleProductChange(productId, formId);
            });
            
            console.log('OA TimberFans: Change listener setup complete');
        }
        
        /**
         * Check for existing selections and perform availability checks
         *
         * @param {number} formId - The form ID
         */
        checkExistingSelections(formId) {
            console.log('OA TimberFans: Checking for existing selections');
            
            const $productField = $(`#field_${formId}_${OATF.field_product}`);
            const $selectedProduct = $productField.find('input[type=radio]:checked');
            
            if ($selectedProduct.length > 0) {
                const productId = $selectedProduct.val();
                console.log('OA TimberFans: Found existing product selection', { productId });
                
                // Perform availability checks for the existing selection
                this.handleProductChange(productId, formId, true);
            } else {
                console.log('OA TimberFans: No existing product selection found');
            }
        }
        
        /**
         * Apply shared CSS classes to form fields
         *
         * @param {number} formId - The form ID
         */
        applySharedClasses(formId) {
            console.log('OA TimberFans: Applying shared classes to fields');
            
            // Apply grid field class to image-based fields (1, 5, 6)
            const gridFields = [1, 5, 6];
            gridFields.forEach(fieldId => {
                const $field = $(`#field_${formId}_${fieldId}`);
                if ($field.length > 0) {
                    $field.addClass('oa-tf-grid-field oa-tf-field');
                    if (fieldId === 5 || fieldId === 6) {
                        $field.addClass('oa-tf-grid-field--6col');
                    }
                    console.log(`OA TimberFans: Applied grid classes to field ${fieldId}`);
                }
            });
            
            // Apply flex field class to button-based fields (4, 7)
            const flexFields = [4, 7];
            flexFields.forEach(fieldId => {
                const $field = $(`#field_${formId}_${fieldId}`);
                if ($field.length > 0) {
                    $field.addClass('oa-tf-flex-field oa-tf-field');
                    console.log(`OA TimberFans: Applied flex classes to field ${fieldId}`);
                }
            });
        }
        
        /**
         * Handle product change
         *
         * @param {string} productId - The selected product ID
         * @param {number} formId - The form ID
         * @param {boolean} isInitialLoad - Whether this is the initial load (editing existing entry)
         */
        handleProductChange(productId, formId, isInitialLoad = false) {
            console.log('OA TimberFans: Fan range changed', { 
                productId, 
                fieldId: OATF.field_product,
                formId: formId,
                isInitialLoad: isInitialLoad
            });
            
            this.addLoadingEffects(formId);
            
            // Only uncheck dependent fields if this is not the initial load
            if (!isInitialLoad) {
                this.uncheckDependentFields(formId);
            }
            
            // Track completion of all AJAX calls
            let completedCalls = 0;
            const totalCalls = 4;
            
            const checkAllComplete = () => {
                completedCalls++;
                if (completedCalls >= totalCalls) {
                    this.removeLoadingEffects(formId);
                    console.log('OA TimberFans: Removed loading effects from all fields and form');
                }
            };
            
            // Get available sizes
            this.getAvailableSizes(productId, formId, checkAllComplete);
            
            // Get available finishes
            this.getAvailableFinishes(productId, formId, checkAllComplete);
            
            // Get available metal finishes
            this.getAvailableMetalFinishes(productId, formId, checkAllComplete);
            
            // Get available speed regulators
            this.getAvailableSpeedRegulators(productId, formId, checkAllComplete);
        }
        
        /**
         * Add loading effects to fields and form
         *
         * @param {number} formId - The form ID
         */
        addLoadingEffects(formId) {
            const $sizeField = $(`#field_${formId}_${OATF.field_size}`);
            const $finishField = $(`#field_${formId}_5`);
            const $metalFinishField = $(`#field_${formId}_6`);
            const $speedRegulatorField = $(`#field_${formId}_${OATF.field_speed_regulator}`);
            
            // Add loading to individual fields only
            $sizeField.addClass('loading');
            $finishField.addClass('loading');
            $metalFinishField.addClass('loading');
            $speedRegulatorField.addClass('loading');
            
            console.log('OA TimberFans: Added loading effects to fields', {
                sizeField: $sizeField.length > 0,
                finishField: $finishField.length > 0,
                metalFinishField: $metalFinishField.length > 0,
                speedRegulatorField: $speedRegulatorField.length > 0
            });
        }
        
        /**
         * Remove loading effects from fields and form
         *
         * @param {number} formId - The form ID
         */
        removeLoadingEffects(formId) {
            const $sizeField = $(`#field_${formId}_${OATF.field_size}`);
            const $finishField = $(`#field_${formId}_5`);
            const $metalFinishField = $(`#field_${formId}_6`);
            const $speedRegulatorField = $(`#field_${formId}_${OATF.field_speed_regulator}`);
            
            // Remove loading from individual fields only
            $sizeField.removeClass('loading');
            $finishField.removeClass('loading');
            $metalFinishField.removeClass('loading');
            $speedRegulatorField.removeClass('loading');
            
            console.log('OA TimberFans: Removed loading effects from fields');
        }
        
        /**
         * Uncheck all radio buttons in dependent fields
         *
         * @param {number} formId - The form ID
         */
        uncheckDependentFields(formId) {
            const $sizeField = $(`#field_${formId}_${OATF.field_size}`);
            const $finishField = $(`#field_${formId}_5`);
            const $metalFinishField = $(`#field_${formId}_6`);
            const $speedRegulatorField = $(`#field_${formId}_${OATF.field_speed_regulator}`);
            
            $sizeField.find('input[type=radio]').prop('checked', false);
            $finishField.find('input[type=radio]').prop('checked', false);
            $metalFinishField.find('input[type=radio]').prop('checked', false);
            $speedRegulatorField.find('input[type=radio]').prop('checked', false);
            
            console.log('OA TimberFans: Unchecked all radio buttons in size, timber, metal, and speed regulator fields');
        }
        
        /**
         * Get available sizes for a product
         *
         * @param {string} productId - The product ID
         * @param {number} formId - The form ID
         * @param {Function} callback - Completion callback
         */
        getAvailableSizes(productId, formId, callback) {
            $.post(OATF.ajax_url, {
                action: 'oa_tf_get_available_sizes',
                product_id: productId
            })
            .done((availableSizes) => {
                console.log('OA TimberFans: AJAX response received for sizes', { 
                    availableSizes, 
                    availableSizesLength: availableSizes.length 
                });
                
                this.updateFieldOptions(formId, OATF.field_size, availableSizes, 'size');
                callback();
            })
            .fail((xhr, status, error) => {
                console.error('OA TimberFans: AJAX request failed for sizes', { 
                    status, 
                    error, 
                    xhr 
                });
                callback();
            });
        }
        
        /**
         * Get available finishes for a product
         *
         * @param {string} productId - The product ID
         * @param {number} formId - The form ID
         * @param {Function} callback - Completion callback
         */
        getAvailableFinishes(productId, formId, callback) {
            $.post(OATF.ajax_url, {
                action: 'oa_tf_get_available_finishes',
                product_id: productId
            })
            .done((availableFinishes) => {
                console.log('OA TimberFans: AJAX response received for finishes', { 
                    availableFinishes, 
                    availableFinishesLength: availableFinishes.length 
                });
                
                this.updateFieldOptions(formId, 5, availableFinishes, 'timber-finish');
                callback();
            })
            .fail((xhr, status, error) => {
                console.error('OA TimberFans: AJAX request failed for finishes', { 
                    status, 
                    error, 
                    xhr 
                });
                callback();
            });
        }
        
        /**
         * Get available metal finishes for a product
         *
         * @param {string} productId - The product ID
         * @param {number} formId - The form ID
         * @param {Function} callback - Completion callback
         */
        getAvailableMetalFinishes(productId, formId, callback) {
            $.post(OATF.ajax_url, {
                action: 'oa_tf_get_available_metal_finishes',
                product_id: productId
            })
            .done((availableMetalFinishes) => {
                console.log('OA TimberFans: AJAX response received for metal finishes', { 
                    availableMetalFinishes, 
                    availableMetalFinishesLength: availableMetalFinishes.length 
                });
                
                this.updateFieldOptions(formId, 6, availableMetalFinishes, 'metal-finish');
                callback();
            })
            .fail((xhr, status, error) => {
                console.error('OA TimberFans: AJAX request failed for metal finishes', { 
                    status, 
                    error, 
                    xhr 
                });
                callback();
            });
        }
        
        /**
         * Get available speed regulators for a product
         *
         * @param {string} productId - The product ID
         * @param {number} formId - The form ID
         * @param {Function} callback - Completion callback
         */
        getAvailableSpeedRegulators(productId, formId, callback) {
            console.log('OA TimberFans: Requesting speed regulators for product', { productId, formId });
            
            $.post(OATF.ajax_url, {
                action: 'oa_tf_get_available_speed_regulators',
                product_id: productId
            })
            .done((availableSpeedRegulators) => {
                console.log('OA TimberFans: AJAX response received for speed regulators', { 
                    availableSpeedRegulators, 
                    availableSpeedRegulatorsLength: availableSpeedRegulators.length 
                });
                
                // Debug: Check if the field exists before updating
                const speedFieldSelector = `#field_${formId}_${OATF.field_speed_regulator}`;
                const $speedField = $(speedFieldSelector);
                console.log('OA TimberFans: Speed regulator field check', {
                    selector: speedFieldSelector,
                    exists: $speedField.length > 0,
                    fieldId: OATF.field_speed_regulator
                });
                
                this.updateFieldOptions(formId, OATF.field_speed_regulator, availableSpeedRegulators, 'speed-regulator');
                callback();
            })
            .fail((xhr, status, error) => {
                console.error('OA TimberFans: AJAX request failed for speed regulators', { 
                    status, 
                    error, 
                    xhr 
                });
                callback();
            });
        }
        
        /**
         * Update field options based on availability
         *
         * @param {number} formId - The form ID
         * @param {number} fieldId - The field ID
         * @param {Array} availableOptions - Available options
         * @param {string} fieldType - The field type
         */
        updateFieldOptions(formId, fieldId, availableOptions, fieldType) {
            // Debug: Log the selector and check if it exists
            const selector = `#field_${formId}_${fieldId} .gfield_radio`;
            const $fieldWrap = $(selector);
            
            console.log('OA TimberFans: Target field wrapper found', { 
                fieldId,
                fieldType,
                wrapperExists: $fieldWrap.length > 0,
                wrapperSelector: selector,
                availableOptions: availableOptions,
                availableOptionsLength: availableOptions.length
            });
            
            const $radioButtons = $fieldWrap.find('input[type=radio]');
            console.log('OA TimberFans: Found radio buttons', { 
                totalRadioButtons: $radioButtons.length 
            });
            
            // Get currently selected value for this field
            const $selectedRadio = $fieldWrap.find('input[type=radio]:checked');
            const selectedValue = $selectedRadio.length > 0 ? $selectedRadio.val() : null;
            
            console.log('OA TimberFans: Current selection for field', { 
                fieldId, 
                selectedValue, 
                isSelectedInAvailable: selectedValue ? availableOptions.indexOf(selectedValue) !== -1 : false
            });
            
            // Hide/show radio buttons based on availability
            $radioButtons.each((index, element) => {
                const $radio = $(element);
                const $label = $radio.closest('label');
                const $gchoice = $radio.closest('.gchoice');
                const radioValue = $radio.val();
                const isCurrentlySelected = radioValue === selectedValue;
                
                console.log('OA TimberFans: Processing radio button', { 
                    value: radioValue,
                    isAvailable: availableOptions.indexOf(radioValue) !== -1,
                    isCurrentlySelected: isCurrentlySelected
                });
                
                // If this option is available OR it's currently selected (for editing), show it
                if (availableOptions.indexOf(radioValue) !== -1 || isCurrentlySelected) {
                    $gchoice.removeClass('oa-tf-unavailable');
                    $label.removeClass('oa-tf-disabled');
                    $label.show();
                    $radio.prop('disabled', false);

                    if (isCurrentlySelected && availableOptions.indexOf(radioValue) === -1) {
                        $gchoice.addClass('oa-tf-unavailable');
                        $radio.prop('disabled', true);
                        console.log('OA TimberFans: Selected option is not available', { radioValue });
                    }
                } else {
                    $gchoice.addClass('oa-tf-unavailable');
                    $label.addClass('oa-tf-disabled');
                    $radio.prop('disabled', true);
                    $radio.prop('checked', false);
                }
            });
            
            // Show message if no options are available
            const visibleOptions = $fieldWrap.find('input[type=radio]:not(:disabled)').length;
            console.log('OA TimberFans: Available options count', { visibleOptions });
            
            if (visibleOptions === 0) {
                $fieldWrap.find('.oa-tf-no-options-available').remove();
                $fieldWrap.append(`<p class="oa-tf-no-options-available">⚠️ No ${fieldType.replace('-', ' ')}s available for the selected fan range. Please choose a different fan range.</p>`);
            } else {
                $fieldWrap.find('.oa-tf-no-options-available').remove();
            }
            
            console.log(`OA TimberFans: ${fieldType} filtering complete`);
        }
    }
    
    // Initialize the plugin when document is ready
    $(document).ready(() => {
        new OATimberFansGFMod();
    });
    
})(jQuery);
