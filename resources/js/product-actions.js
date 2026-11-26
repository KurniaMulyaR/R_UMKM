import Swal from 'sweetalert2';

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('form[data-swal-confirm]');
    if (!form || form.dataset.confirmed === 'true') {
        return;
    }

    event.preventDefault();
    const result = await Swal.fire({
        title: form.dataset.swalTitle || 'Lanjutkan?',
        text: form.dataset.swalText || 'Periksa kembali sebelum melanjutkan.',
        icon: form.dataset.swalIcon || 'question',
        showCancelButton: true,
        confirmButtonText: form.dataset.swalConfirmText || 'Ya, lanjutkan',
        cancelButtonText: 'Batal',
        reverseButtons: true,
    });

    if (result.isConfirmed) {
        form.dataset.confirmed = 'true';
        form.requestSubmit();
    }
});
