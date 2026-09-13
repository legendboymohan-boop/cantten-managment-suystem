// CanteenPro - client-side helpers
document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss alerts after 4 seconds
    document.querySelectorAll('.alert').forEach(function (el) {
        setTimeout(function () { el.style.display = 'none'; }, 4000);
    });
});
