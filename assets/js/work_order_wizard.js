$(document).ready(function () {
    let currentStep = 1;
    const totalSteps = 4;

    function refreshIcons() {
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
    }

    function updateStepUI() {
        for (let step = 1; step <= totalSteps; step++) {
            const circle = $('#step-circle-' + step);
            const label = $('#step-label-' + step);
            const line = $('#step-line-' + step);
            const content = $('#step-' + step);

            if (step === currentStep) {
                circle.removeClass('bg-gray-300 text-gray-600').addClass('bg-indigo-600 text-white');
                label.removeClass('text-gray-500').addClass('text-indigo-700');
                content.removeClass('hidden');
            } else {
                circle.removeClass('bg-indigo-600 text-white');
                if (step < currentStep) {
                    circle.addClass('bg-green-500 text-white');
                    label.removeClass('text-gray-500').addClass('text-green-700');
                } else {
                    circle.addClass('bg-gray-300 text-gray-600');
                    label.removeClass('text-indigo-700 text-green-700').addClass('text-gray-500');
                }
                content.addClass('hidden');
            }

            if (line.length) {
                line.toggleClass('bg-green-500', step < currentStep).toggleClass('bg-gray-300', step >= currentStep);
            }
        }

        $('#btn-prev').prop('disabled', currentStep === 1);
        $('#btn-next').toggleClass('hidden', currentStep === totalSteps);
        $('#btn-submit').toggleClass('hidden', currentStep !== totalSteps);
        refreshIcons();
    }

    function validateStep(step) {
        if (step === 1) {
            const scratchMode = $('#workOrderForm').data('scratch-mode') === 1 || $('#workOrderForm').data('scratch-mode') === '1';
            if (scratchMode) {
                const invoiceId = $('#invoice_id').val();
                if (!invoiceId) {
                    alert('Please select an invoice to link this work order to.');
                    $('#invoice_selector').focus();
                    return false;
                }
            }
        }
        if (step === 2) {
            const bindingId = $('#binding_type_id').val();
            const bindingName = $('#binding_type_name').val().trim();
            if (!bindingId && !bindingName) {
                alert('Please select a type of binding before continuing.');
                $('#binding_type_id').focus();
                return false;
            }
        }
        return true;
    }

    $('#btn-next').on('click', function () {
        if (!validateStep(currentStep)) {
            return;
        }
        if (currentStep < totalSteps) {
            currentStep++;
            updateStepUI();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });

    $('#btn-prev').on('click', function () {
        if (currentStep > 1) {
            currentStep--;
            updateStepUI();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });

    function formatMoney(amount) {
        const value = Number(amount) || 0;
        return 'MK ' + value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function applyInvoicePrefill(data) {
        $('#invoice_id').val(data.invoice_id || '');
        $('#summary-customer').text(data.customer_name || '—');
        $('#summary-total').text(formatMoney(data.total_cost));
        $('#summary-balance').text(formatMoney(data.balance));
        $('#step4-total').text(formatMoney(data.total_cost));
        $('#step4-paid').text(formatMoney(data.amount_paid));
        $('#step4-balance').text(formatMoney(data.balance));

        $('#ministry_department').val(data.ministry_department || '');
        $('#quantity').val(data.quantity ?? '');
        $('#pages_count').val(data.pages_count ?? '');
        $('#size_deep').val(data.size_deep || '');
        $('#size_wide').val(data.size_wide || '');
        $('#job_description').val(data.job_description || '');

        const orderRef = $('#order_ref_lpo');
        const userEdited = orderRef.data('user-edited') === 1 || orderRef.data('user-edited') === '1';
        const autoFilled = orderRef.data('auto-filled') === 1 || orderRef.data('auto-filled') === '1';
        if (!userEdited || autoFilled) {
            orderRef.val(data.order_ref_lpo || data.invoice_number || '');
            orderRef.data('auto-filled', '1');
            orderRef.data('user-edited', '0');
        }

        let subtitle = 'Complete the costing traveler for invoice <strong>' + (data.invoice_number || '') + '</strong>';
        if (data.estimation_number) {
            subtitle += ' linked to estimation <strong>' + data.estimation_number + '</strong>';
        }
        $('#create-subtitle').html(subtitle);
        var workOrderForm = document.getElementById('workOrderForm');
        if (workOrderForm && window.FormUnsavedGuard) {
            window.FormUnsavedGuard.resetBaseline(workOrderForm);
        }
    }

    $('#order_ref_lpo').on('input', function () {
        $(this).data('user-edited', '1').data('auto-filled', '0');
    });

    $('#invoice_selector').on('change', function () {
        const invoiceId = $(this).val();
        const status = $('#invoice-prefill-status');
        if (!invoiceId) {
            $('#invoice_id').val('');
            status.text('');
            return;
        }

        status.text('Loading invoice details…');
        $.getJSON('invoice_prefill.php', { invoice_id: invoiceId })
            .done(function (response) {
                if (!response.success) {
                    status.text(response.message || 'Could not load invoice.');
                    if (response.existing_work_order_id) {
                        window.location.href = 'view?id=' + response.existing_work_order_id;
                    }
                    return;
                }
                applyInvoicePrefill(response.data);
                status.text('Invoice linked. Order reference set to ' + (response.data.invoice_number || 'invoice number') + '.');
            })
            .fail(function () {
                status.text('Could not load invoice details. Please try again.');
            });
    });

    $('#binding_type_id').on('change', function () {
        const selected = $(this).find('option:selected');
        $('#binding_type_name').val(selected.val() ? selected.text().trim() : '');
    });

    $('#btn-add-binding-type').on('click', function () {
        $('#bindingTypeModal').removeClass('hidden');
        $('#new_binding_type_name').val('').focus();
    });

    $('#btn-cancel-binding-type').on('click', function () {
        $('#bindingTypeModal').addClass('hidden');
    });

    $('#bindingTypeForm').on('submit', function (event) {
        event.preventDefault();
        const name = $('#new_binding_type_name').val().trim();
        if (!name) {
            return;
        }

        $.post('add_binding_type.php', $(this).serialize())
            .done(function (response) {
                if (!response.success) {
                    alert(response.message || 'Could not add binding type.');
                    return;
                }
                const option = $('<option>', { value: response.id, text: response.name, selected: true });
                $('#binding_type_id').append(option);
                $('#binding_type_name').val(response.name);
                $('#bindingTypeModal').addClass('hidden');
            })
            .fail(function () {
                alert('Could not add binding type. Please try again.');
            });
    });

    $('#add-paper-row').on('click', function () {
        const template = document.getElementById('paper-row-template');
        if (!template) {
            return;
        }
        const clone = template.content.cloneNode(true);
        $('#paper-rows').append(clone);
        refreshIcons();
    });

    $(document).on('click', '.remove-paper-row', function () {
        $(this).closest('tr').remove();
    });

    $('.dept-toggle').on('click', function () {
        const target = $($(this).data('target'));
        target.toggleClass('hidden');
        const icon = $(this).find('[data-lucide]');
        const expanded = !target.hasClass('hidden');
        $(this).attr('aria-expanded', expanded ? 'true' : 'false');
        icon.attr('data-lucide', expanded ? 'chevron-up' : 'chevron-down');
        refreshIcons();
    });

    $('#workOrderForm').on('submit', function (event) {
        if (!validateStep(2)) {
            event.preventDefault();
            currentStep = 2;
            updateStepUI();
        }
    });

    updateStepUI();
    setTimeout(function () {
        var workOrderForm = document.getElementById('workOrderForm');
        if (workOrderForm && window.FormUnsavedGuard) {
            window.FormUnsavedGuard.resetBaseline(workOrderForm);
        }
    }, 0);
});
