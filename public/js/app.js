document.addEventListener('DOMContentLoaded', function () {
    console.log('Laravel app loaded without Vite.');

    const forms = document.querySelectorAll('form');

    forms.forEach(function (form) {
        form.addEventListener('submit', function () {
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                const originalText = button.textContent;
                button.textContent = 'Моля, изчакайте...';

                setTimeout(function () {
                    button.disabled = false;
                    button.textContent = originalText;
                }, 2000);
            }
        });
    });
});