$('#saveForm input[type="number"]').on('keyup change', function () {
    let boxCounting = 0;

    $('#saveForm input[type="number"]').each(function () {
        // obtenemos el nombre del input y reemplazamos el guion bajo por punto
        let currency = $(this).attr('name').replace('_', '.');

        // obtenemos la cantidad
        let quantity = parseFloat($(this).val());

        // si es NaN, lo ponemos a 0
        if (isNaN(quantity)) {
            quantity = 0;
            $(this).val(0);
        }

        // multiplicamos la cantidad por el valor de la moneda
        boxCounting += quantity * parseFloat(currency);
    });

    $('#boxCounting').text(boxCounting.toFixed(2));
});