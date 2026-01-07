jQuery(function ($) {
    function updateHidden($picker) {
        var date = $picker.find('.event-banner-date').val();
        var time = $picker.find('.event-banner-time').val();
        var value = '';
        if (date) {
            value = date + (time ? ' ' + time : '');
        }
        $picker.find('.event-banner-datetime').val(value);
    }

    $('.event-banner-date').datepicker({
        dateFormat: 'yy-mm-dd'
    });

    $('.event-banner-picker').each(function () {
        updateHidden($(this));
    });

    $(document).on('change', '.event-banner-date, .event-banner-time', function () {
        updateHidden($(this).closest('.event-banner-picker'));
    });
});
