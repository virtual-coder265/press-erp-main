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
});
