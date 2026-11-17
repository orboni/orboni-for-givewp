/**
 * Orboni on GiveWP's visual builder forms: no fields to fill, only what
 * happens when the donor presses Donate. The words come from the server
 * (Orboni_GiveWP_Gateway::formSettings), already in the site's language.
 */
(function () {
    var el = window.React.createElement;

    var gateway = {
        id: 'orboni',
        Fields: function () {
            var settings = gateway.settings || {};

            return el('div', { className: 'orboni-givewp-note' }, el('p', null, settings.message || ''));
        },
    };

    window.givewp.gateways.register(gateway);
})();
