(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-collection-dialog]');
        if (!button) { return; }
        var dialog = document.getElementById(button.dataset.collectionDialog);
        if (dialog && !dialog.open) { dialog.showModal(); }
    });
}());
